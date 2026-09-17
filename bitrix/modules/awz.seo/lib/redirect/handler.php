<?php
namespace Awz\Seo\Redirect;

use Bitrix\Main\Application;
use Bitrix\Main\Config\Option;
use Bitrix\Main\HttpRequest;

/**
 * SEO-редиректы, обрабатываемые на событии main::OnPageStart (ядро D7).
 * Настройки хранятся per-site (многосайтовость): Option::get($moduleId, $name, $default, SITE_ID)
 */
class Handler
{
    public const MODULE_ID = 'awz.seo';

    /** редирект с множества слешей на один: //path -> /path */
    public const OPT_MULTI_SLASH = 'REDIRECT_MULTI_SLASH';
    /** редирект без слеша на со слешем: /path -> /path/ (слеш ставится перед GET-параметрами) */
    public const OPT_SLASH_END = 'REDIRECT_SLASH_END';
    /** редирект с http на https */
    public const OPT_HTTPS = 'REDIRECT_HTTPS';
    /** редирект с index.php на слеш: /index.php -> /, /path/index.php -> /path/ */
    public const OPT_INDEX_SLASH = 'REDIRECT_INDEX_SLASH';
    /** код ответа редиректа: 301 или 302 */
    public const OPT_CODE = 'REDIRECT_CODE';
    /**
     * www-редирект (только одно направление активно, чтобы не было зацикливания):
     * 'N'  - выключен
     * 'W'  - www -> без www (убрать префикс www)
     * 'NW' - без www -> www (добавить префикс www)
     */
    public const OPT_WWW = 'REDIRECT_WWW';
    /** путь к CSV-файлу с кастомными редиректами (относительно DOCUMENT_ROOT, например /upload/redirects.csv) */
    public const OPT_CUSTOM = 'REDIRECT_CUSTOM_PATH';
    /** максимальная длина цепочки редиректов (защита от зацикливания) */
    public const MAX_REDIRECT_CHAIN = 5;
    /** имя cookie-счётчика цепочки редиректов */
    public const REDIRECT_COUNT_COOKIE = 'awz_seo_redirect_count';
    /** срок жизни cookie-счётчика, сек (хватает на цепочку, затем истекает) */
    public const REDIRECT_COUNT_TTL = 30;

    public static function OnPageStart(): void
    {
        $application = Application::getInstance();
        $context = $application->getContext();
        $request = $context->getRequest();

        if (!($request instanceof HttpRequest))
            return;
        //не трогаем админку, служебные запросы и POST (чтобы не терять данные форм)
        if ($request->isAdminSection())
            return;
        if ($request->isPost() || $request->getRequestMethod() === 'OPTIONS')
            return;
        if ($request->isAjaxRequest())
            return;

        $siteId = defined('SITE_ID') ? SITE_ID : '';
        if ($siteId === '')
            return;

        $multiSlash = Option::get(self::MODULE_ID, self::OPT_MULTI_SLASH, 'N', $siteId) === 'Y';
        $slashEnd = Option::get(self::MODULE_ID, self::OPT_SLASH_END, 'N', $siteId) === 'Y';
        $forceHttps = Option::get(self::MODULE_ID, self::OPT_HTTPS, 'N', $siteId) === 'Y';
        $indexSlash = Option::get(self::MODULE_ID, self::OPT_INDEX_SLASH, 'N', $siteId) === 'Y';
        $wwwMode = Option::get(self::MODULE_ID, self::OPT_WWW, 'N', $siteId);
        $customRedirects = self::getCustomRedirects($siteId);

        if (!$multiSlash && !$slashEnd && !$forceHttps && !$indexSlash && $wwwMode === 'N' && empty($customRedirects))
            return;

        //защита от зацикливания: если цепочка уже достигла лимита - не редиректим
        $redirectCount = (int)($_COOKIE[self::REDIRECT_COUNT_COOKIE] ?? 0);
        if ($redirectCount >= self::MAX_REDIRECT_CHAIN)
            return;

        $uri = (string)$request->getServer()->get('REQUEST_URI');
        if ($uri === '' || !preg_match('#^/#', $uri))
            return;

        //разделяем путь и GET-параметры: редиректы применяются только к пути,
        //слеш всегда ставится перед знаком "?"
        $pos = strpos($uri, '?');
        $path = $pos === false ? $uri : substr($uri, 0, $pos);
        $query = $pos === false ? null : substr($uri, $pos + 1);

        //не обрабатываем служебные URL ядра
        if (strpos($path, '/bitrix/') === 0)
            return;

        $https = self::isHttps($request);
        $curScheme = $https ? 'https' : 'http';
        $host = self::getHost($request, $curScheme);
        if ($host === '')
            return;

        //1) кастомные редиректы (из CSV) - имеют приоритет над остальными
        if (!empty($customRedirects)) {
            $curFullUrl = $curScheme.'://'.$host.$path;
            $target = self::matchCustomRedirect($customRedirects, $curFullUrl, $path);
            if ($target !== null) {
                $newUrl = self::buildRedirectUrl($target, $request, $query);
                $curFullUrlWithQuery = $curFullUrl.($query !== null ? '?'.$query : '');
                if ($newUrl !== '' && strcasecmp($newUrl, $curFullUrlWithQuery) !== 0) {
                    self::issueRedirect($application, $context, $siteId, $newUrl, $redirectCount);
                    return;
                }
            }
        }

        //2) остальные редиректы: нормализация пути, www, https
        $newPath = self::normalizePath($path, $multiSlash, $indexSlash, $slashEnd);
        $newScheme = ($https || $forceHttps) ? 'https' : 'http';
        $newUri = $newPath.($query !== null ? '?'.$query : '');
        $curUri = $path.($query !== null ? '?'.$query : '');

        //www-редирект: нормализуем хост (только одно направление активно - нет цикла)
        $newHost = self::normalizeHost($host, $wwwMode);

        if ($newScheme === $curScheme && $newUri === $curUri && $newHost === $host)
            return; //нет изменений - редирект не нужен

        self::issueRedirect($application, $context, $siteId, $newScheme.'://'.$newHost.$newUri, $redirectCount);
    }

    /**
     * Пересобирает путь URL с учётом включённых опций
     */
    public static function normalizePath(string $path, bool $multiSlash, bool $indexSlash, bool $slashEnd): string
    {
        //1) схлопываем несколько слешей подряд в один
        if ($multiSlash) {
            $path = (string)preg_replace('#/{2,}#', '/', $path);
        }

        //2) index.php -> слеш (в конце пути или корень сайта)
        if ($indexSlash) {
            if ($path === '/index.php') {
                $path = '/';
            } elseif (substr($path, -10) === '/index.php') {
                $path = substr($path, 0, -10).'/';
            }
        }

        //3) отсутствие завершающего слеша -> добавляем "/".
        //пути, похожие на файлы (с расширением, т.е. с точкой в последнем сегменте), не трогаем
        if ($slashEnd && $path !== '/' && substr($path, -1) !== '/') {
            $basename = substr($path, strrpos($path, '/') + 1);
            if (strpos($basename, '.') === false) {
                $path .= '/';
            }
        }

        return ($path === '') ? '/' : $path;
    }

    /**
     * Нормализует хост с учётом www-редиректа.
     * Только одно направление активно одновременно, поэтому зацикливание исключено.
     * @param string $host текущий хост
     * @param string $mode 'N' (выкл), 'W' (www -> non-www), 'NW' (non-www -> www)
     * @return string новый хост
     */
    public static function normalizeHost(string $host, string $mode): string
    {
        if ($mode === 'W') {
            //www -> non-www: убираем префикс www.
            if (preg_match('#^www\.(.+)$#i', $host, $m)) {
                return $m[1];
            }
        } elseif ($mode === 'NW') {
            //non-www -> www: добавляем префикс www.
            if (!preg_match('#^www\.#i', $host)) {
                return 'www.'.$host;
            }
        }
        return $host;
    }

    /** статический кэш разбора CSV на время запроса: [siteId => ['mtime'=>.., 'rows'=>..]] */
    protected static $csvCache = [];

    /**
     * Читает кастомные редиректы из CSV-файла, путь к которому задан в настройках сайта.
     * Файл читается по пути относительно DOCUMENT_ROOT. Разбор кэшируется на время запроса
     * и обновляется при изменении mtime файла.
     * @return array массив [['FROM'=>..,'TO'=>..], ...]
     */
    public static function getCustomRedirects(string $siteId): array
    {
        if (isset(self::$csvCache[$siteId])) {
            return self::$csvCache[$siteId]['rows'];
        }

        $path = trim((string)Option::get(self::MODULE_ID, self::OPT_CUSTOM, '', $siteId));
        if ($path === '')
            return [];

        //санитизация пути
        $path = str_replace(['..', '\\'], '', $path);
        $path = ltrim($path, '/');
        if ($path === '')
            return [];
        $fullPath = self::getDocumentRoot().'/'.$path;
        $realPath = realpath($fullPath);
        $docRoot = realpath(self::getDocumentRoot());
        if ($realPath === false || $docRoot === false || strpos($realPath, $docRoot) !== 0)
            return [];
        if (!is_file($realPath))
            return [];

        $content = @file_get_contents($realPath);
        $rows = ($content !== false) ? self::parseCsvContent($content) : [];
        self::$csvCache[$siteId] = ['rows' => $rows];
        return $rows;
    }

    /**
     * Разбирает содержимое CSV с парами "старый URL, новый URL".
     * Разделитель — запятая или точка с запятой (определяется по первой строке).
     * "Старый URL" может быть полным (с https:// и хостом) или путём.
     * @param string $content содержимое CSV
     * @return array массив [['FROM'=>..,'TO'=>..], ...]
     */
    public static function parseCsvContent(string $content): array
    {
        if (trim($content) === '')
            return [];
        $content = (string)preg_replace('/^\xEF\xBB\xBF/', '', $content);
        $firstLine = (string)strtok($content, "\r\n");
        $separator = (substr_count($firstLine, ';') > substr_count($firstLine, ',')) ? ';' : ',';

        $rows = [];
        $lines = preg_split('/\r\n|\r|\n/', $content);
        foreach ($lines as $line) {
            $line = trim((string)$line);
            if ($line === '')
                continue;
            $parts = str_getcsv($line, $separator);
            if (!is_array($parts) || count($parts) < 2)
                continue;
            $from = trim((string)$parts[0]);
            $to = trim((string)$parts[1]);
            if ($from === '' || $to === '')
                continue;
            $rows[] = ['FROM' => $from, 'TO' => $to];
        }
        return $rows;
    }

    protected static function getDocumentRoot(): string
    {
        return (string)($_SERVER['DOCUMENT_ROOT'] ?? '');
    }

    /**
     * Ищет совпадение в кастомных редиректах.
     * "FROM" может быть полным URL (с схемой/хостом) или путём.
     * @param array $customRedirects массив [['FROM'=>..,'TO'=>..], ...]
     * @param string $currentFullUrl текущий полный URL (scheme://host/path, без query)
     * @param string $currentPath текущий путь
     * @return string|null цель редиректа или null
     */
    public static function matchCustomRedirect(array $customRedirects, string $currentFullUrl, string $currentPath): ?string
    {
        foreach ($customRedirects as $redirect) {
            $from = trim((string)($redirect['FROM'] ?? ''));
            $to = trim((string)($redirect['TO'] ?? ''));
            if ($from === '' || $to === '')
                continue;

            if (preg_match('#^https?://#i', $from)) {
                //полный URL: сравниваем с текущим полным URL (без учёта регистра)
                if (strcasecmp($from, $currentFullUrl) === 0) {
                    return $to;
                }
            } else {
                //путь: сравниваем с текущим путём
                if ($from === $currentPath) {
                    return $to;
                }
            }
        }
        return null;
    }

    /**
     * Строит полный URL редиректа из цели (полный URL или путь).
     * @param string $target цель (полный URL или путь)
     * @param HttpRequest $request текущий запрос
     * @param string|null $query GET-строка (без "?")
     * @return string полный URL или '' если не удалось построить
     */
    protected static function buildRedirectUrl(string $target, HttpRequest $request, ?string $query): string
    {
        $target = trim($target);
        if ($target === '')
            return '';
        if (preg_match('#^https?://#i', $target)) {
            //полный URL - используем как есть
            return $target;
        }
        //путь - собираем полный URL из текущей схемы и хоста
        $https = self::isHttps($request);
        $scheme = $https ? 'https' : 'http';
        $host = self::getHost($request, $scheme);
        if ($host === '')
            return '';
        $uri = $target.($query !== null ? '?'.$query : '');
        return $scheme.'://'.$host.$uri;
    }

    /**
     * Выставляет редирект и увеличивает счётчик цепочки (защита от зацикливания).
     * @param int $redirectCount текущая длина цепочки (из cookie)
     */
    protected static function issueRedirect($application, $context, string $siteId, string $newUrl, int $redirectCount): void
    {
        $code = Option::get(self::MODULE_ID, self::OPT_CODE, '301', $siteId);
        $code = ($code === '302') ? 302 : 301;

        //счётчик цепочки: браузер вернёт cookie на следующем запросе цепочки
        if (!headers_sent()) {
            setcookie(
                self::REDIRECT_COUNT_COOKIE,
                (string)($redirectCount + 1),
                time() + self::REDIRECT_COUNT_TTL,
                '/'
            );
        }

        $response = $context->getResponse()->redirectTo($newUrl);
        $response->setStatus($code);
        $application->end(0, $response);
    }

    protected static function isHttps(HttpRequest $request): bool
    {
        $server = $request->getServer();
        if (strtolower((string)$server->get('HTTPS')) === 'on')
            return true;
        if ((int)$server->get('SERVER_PORT') === 443)
            return true;
        //работа за обратным прокси
        if (strtolower((string)$server->get('HTTP_X_FORWARDED_PROTO')) === 'https')
            return true;

        return false;
    }

    protected static function getHost(HttpRequest $request, string $scheme = 'http'): string
    {
        $host = (string)$request->getServer()->get('HTTP_HOST');
        if ($host === '')
            $host = (string)$request->getServer()->get('SERVER_NAME');

        //защита от инъекции в заголовок Location
        $host = (string)preg_replace('#[^a-zA-Z0-9\.\-\:]#', '', $host);

        //убираем стандартный порт схемы: https://host:443 -> https://host, http://host:80 -> http://host
        if (preg_match('#^(.+):(\d+)$#', $host, $matches)) {
            $port = (int)$matches[2];
            if (($scheme === 'https' && $port === 443) || ($scheme === 'http' && $port === 80)) {
                $host = $matches[1];
            }
        }

        return $host;
    }
}
