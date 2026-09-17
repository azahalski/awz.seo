<?php
namespace Awz\Seo\Canonical;

use Bitrix\Main\Application;
use Bitrix\Main\Config\Option;
use Bitrix\Main\HttpRequest;

/**
 * Тег <link rel="canonical">, добавляемый на событии main::OnEpilog (ядро D7).
 * Настройки хранятся per-site (многосайтовость): Option::get($moduleId, $name, $default, SITE_ID)
 */
class Handler
{
    public const MODULE_ID = 'awz.seo';

    /** режим: N - выключен, A - всегда, G - только при наличии GET-параметров */
    public const OPT_MODE = 'CANONICAL_MODE';
    /** страницы пагинации не канонические: из canonical исключаются PAGEN_N параметры */
    public const OPT_PAGEN_NONCANONICAL = 'CANONICAL_PAGEN_NONCANONICAL';
    /** исключать из canonical все GET-параметры (кроме перечисленных в CANONICAL_KEEP_GET) */
    public const OPT_STRIP_GET = 'CANONICAL_STRIP_GET';
    /** список GET-параметров через запятую, которые всегда сохраняются в canonical */
    public const OPT_KEEP_GET = 'CANONICAL_KEEP_GET';
    /** canonical всегда с https */
    public const OPT_HTTPS = 'CANONICAL_HTTPS';
    /** заменять index.php на слеш в canonical */
    public const OPT_INDEX_SLASH = 'CANONICAL_INDEX_SLASH';
    /** не выводить canonical на страницах ошибок (404 и др.) */
    public const OPT_SKIP_ERRORS = 'CANONICAL_SKIP_ERRORS';
    /** перезаписывать canonical, уже установленный компонентами/шаблоном */
    public const OPT_OVERRIDE = 'CANONICAL_OVERRIDE';
    /** множественные правила preg_replace для canonical URL: сериализованный массив ['FROM'=>pattern, 'TO'=>replacement] */
    public const OPT_REGEX = 'CANONICAL_REGEX';
    /** максимальное количество правил preg_replace */
    public const REGEX_MAX_RULES = 30;

    public static function OnEpilog(): void
    {
        /** @global \CMain $APPLICATION */
        global $APPLICATION;

        $request = Application::getInstance()->getContext()->getRequest();
        if (!($request instanceof HttpRequest))
            return;
        if ($request->isAdminSection())
            return;
        if ($request->isPost() || $request->getRequestMethod() === 'OPTIONS')
            return;

        $siteId = defined('SITE_ID') ? SITE_ID : '';
        if ($siteId === '')
            return;

        $mode = Option::get(self::MODULE_ID, self::OPT_MODE, 'N', $siteId);
        if ($mode !== 'A' && $mode !== 'G')
            return;

        $override = Option::get(self::MODULE_ID, self::OPT_OVERRIDE, 'N', $siteId) === 'Y';
        $hasCanonical = (string)$APPLICATION->GetProperty('canonical', '') !== ''
            || self::hasCanonicalInHeadStrings();
        if (!$override && $hasCanonical)
            return; //canonical уже установлен компонентом (SetPageProperty или AddHeadString) - не мешаем

        if (Option::get(self::MODULE_ID, self::OPT_SKIP_ERRORS, 'Y', $siteId) === 'Y' && self::isErrorPage())
            return;

        $uri = (string)$request->getServer()->get('REQUEST_URI');
        if ($uri === '' || !preg_match('#^/#', $uri))
            return;

        $pos = strpos($uri, '?');
        $path = $pos === false ? $uri : substr($uri, 0, $pos);
        $query = $pos === false ? null : substr($uri, $pos + 1);

        if (strpos($path, '/bitrix/') === 0)
            return;

        //canonical всегда чистый: схлопываем лишние слеши
        $path = (string)preg_replace('#/{2,}#', '/', $path);

        if (Option::get(self::MODULE_ID, self::OPT_INDEX_SLASH, 'Y', $siteId) === 'Y') {
            if ($path === '/index.php') {
                $path = '/';
            } elseif (substr($path, -10) === '/index.php') {
                $path = substr($path, 0, -10).'/';
            }
        }

        $getPairs = self::parseQueryPairs((string)$query);
        $hasGet = !empty($getPairs);

        //режим "только если есть GET-параметры"
        if ($mode === 'G' && !$hasGet)
            return;

        $pagenNonCanonical = Option::get(self::MODULE_ID, self::OPT_PAGEN_NONCANONICAL, 'N', $siteId) === 'Y';
        $stripGet = Option::get(self::MODULE_ID, self::OPT_STRIP_GET, 'N', $siteId) === 'Y';
        $keepGet = self::parseKeepList((string)Option::get(self::MODULE_ID, self::OPT_KEEP_GET, '', $siteId));

        $newPairs = [];
        foreach ($getPairs as $key => $pairs) {
            //страницы пагинации не канонические: убираем PAGEN_N
            if ($pagenNonCanonical && preg_match('#^pagen_\d+$#', $key))
                continue;
            //полная зачистка GET, кроме разрешённых параметров
            if ($stripGet && !in_array($key, $keepGet, true))
                continue;
            foreach ($pairs as $pair)
                $newPairs[] = $pair;
        }

        $newUri = $path.(!empty($newPairs) ? '?'.implode('&', $newPairs) : '');

        $https = self::isHttps($request)
            || Option::get(self::MODULE_ID, self::OPT_HTTPS, 'N', $siteId) === 'Y';

        $host = self::getHost($request);
        if ($host === '')
            return;

        $canonical = ($https ? 'https' : 'http').'://'.$host.$newUri;
        $canonical = self::applyRegexRules(
            $canonical,
            (string)Option::get(self::MODULE_ID, self::OPT_REGEX, '', $siteId)
        );

        if ($canonical !== '') {
            $APPLICATION->SetPageProperty(
                'canonical',
                htmlspecialcharsbx($canonical)
            );
        }
    }

    /**
     * Применяет множественные правила preg_replace (from => to) к canonical URL.
     * Некорректные/ошибочные шаблоны пропускаются.
     */
    protected static function applyRegexRules(string $url, string $serialized): string
    {
        if ($serialized === '')
            return $url;

        $rules = unserialize($serialized, ['allowed_classes' => false]);
        if (!is_array($rules))
            return $url;

        $i = 0;
        foreach ($rules as $rule) {
            if (!is_array($rule))
                continue;
            $from = (string)($rule['FROM'] ?? '');
            $to = (string)($rule['TO'] ?? '');
            if ($from === '' || $i >= self::REGEX_MAX_RULES)
                continue;
            $i++;
            //проверка валидности регулярки
            if (@preg_match($from, '') === false)
                continue;
            $result = @preg_replace($from, $to, $url);
            if (is_string($result))
                $url = $result;
        }

        return $url;
    }

    /**
     * Проверяет, не добавлен ли тег <link rel="canonical"> через AddHeadString/Asset::addString
     * (в этом случае SetPageProperty('canonical') не установлен, но тег уже будет в <head>).
     */
    protected static function hasCanonicalInHeadStrings(): bool
    {
        $asset = \Bitrix\Main\Page\Asset::getInstance();
        $locations = [
            \Bitrix\Main\Page\AssetLocation::BEFORE_CSS,
            \Bitrix\Main\Page\AssetLocation::AFTER_CSS,
            \Bitrix\Main\Page\AssetLocation::AFTER_JS_KERNEL,
            \Bitrix\Main\Page\AssetLocation::AFTER_JS,
        ];
        foreach ($locations as $location) {
            $strings = (string)$asset->getStrings($location);
            if ($strings !== '' && preg_match('#<link[^>]*rel=["\']canonical["\'][^>]*>#i', $strings)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Разбирает query-строку на "сырые" пары key=>[pair,...] (ключи в нижнем регистре),
     * сохраняя исходное кодирование значений
     */
    protected static function parseQueryPairs(string $query): array
    {
        $result = [];
        if ($query === '')
            return $result;
        foreach (explode('&', $query) as $pair) {
            if ($pair === '')
                continue;
            $key = explode('=', $pair, 2)[0];
            $key = strtolower(rawurldecode($key));
            $result[$key][] = $pair;
        }
        return $result;
    }

    protected static function parseKeepList(string $str): array
    {
        $list = [];
        foreach (explode(',', $str) as $item) {
            $item = strtolower(trim($item));
            if ($item !== '')
                $list[] = $item;
        }
        return $list;
    }

    protected static function isErrorPage(): bool
    {
        $status = (string)\CHTTP::GetLastStatus();
        if (preg_match('#^[45]\d\d#', $status))
            return true;
        $code = http_response_code();
        if (is_int($code) && $code >= 400)
            return true;
        return false;
    }

    protected static function isHttps(HttpRequest $request): bool
    {
        $server = $request->getServer();
        if (strtolower((string)$server->get('HTTPS')) === 'on')
            return true;
        if ((int)$server->get('SERVER_PORT') === 443)
            return true;
        if (strtolower((string)$server->get('HTTP_X_FORWARDED_PROTO')) === 'https')
            return true;

        return false;
    }

    protected static function getHost(HttpRequest $request): string
    {
        $host = (string)$request->getServer()->get('HTTP_HOST');
        if ($host === '')
            $host = (string)$request->getServer()->get('SERVER_NAME');

        $host = preg_replace('#[^a-zA-Z0-9\.\-\:]#', '', $host);

        return (string)$host;
    }
}
