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

        if (!$multiSlash && !$slashEnd && !$forceHttps && !$indexSlash)
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

        $newPath = self::normalizePath($path, $multiSlash, $indexSlash, $slashEnd);

        $https = self::isHttps($request);
        $newScheme = ($https || $forceHttps) ? 'https' : 'http';

        $newUri = $newPath.($query !== null ? '?'.$query : '');
        $curUri = $path.($query !== null ? '?'.$query : '');

        if ($newScheme === ($https ? 'https' : 'http') && $newUri === $curUri)
            return; //нет изменений - редирект не нужен

        $host = self::getHost($request, $newScheme);
        if ($host === '')
            return;

        $code = Option::get(self::MODULE_ID, self::OPT_CODE, '301', $siteId);
        $code = ($code === '302') ? 302 : 301;
        $response = $context->getResponse()->redirectTo($newScheme.'://'.$host.$newUri);
        $response->setStatus($code);
        $application->end(0, $response);
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
