<?php
namespace Awz\Seo\Checker;

use Awz\Seo\Canonical\Handler as CanonicalHandler;
use Awz\Seo\Redirect\Handler as RedirectHandler;
use Bitrix\Main\Config\Option;

/**
 * Общая логика формирования настроек и тест-кейсов для проверки редиректов/canonical.
 * Используется API-контроллером (lib/api/controller/seo.php) и страницей настроек (options.php)
 */
class Checker
{
    /**
     * Читает все настройки модуля для сайта
     */
    public static function getSiteOptions(string $siteId): array
    {
        $regexRules = unserialize(
            (string)Option::get(CanonicalHandler::MODULE_ID, CanonicalHandler::OPT_REGEX, '', $siteId),
            ['allowed_classes' => false]
        );
        if (!is_array($regexRules))
            $regexRules = [];

        return [
            'REDIRECT_MULTI_SLASH' => Option::get(RedirectHandler::MODULE_ID, RedirectHandler::OPT_MULTI_SLASH, 'N', $siteId),
            'REDIRECT_SLASH_END' => Option::get(RedirectHandler::MODULE_ID, RedirectHandler::OPT_SLASH_END, 'N', $siteId),
            'REDIRECT_HTTPS' => Option::get(RedirectHandler::MODULE_ID, RedirectHandler::OPT_HTTPS, 'N', $siteId),
            'REDIRECT_INDEX_SLASH' => Option::get(RedirectHandler::MODULE_ID, RedirectHandler::OPT_INDEX_SLASH, 'N', $siteId),
            'REDIRECT_CODE' => Option::get(RedirectHandler::MODULE_ID, RedirectHandler::OPT_CODE, '301', $siteId),
            'CANONICAL_MODE' => Option::get(CanonicalHandler::MODULE_ID, CanonicalHandler::OPT_MODE, 'N', $siteId),
            'CANONICAL_PAGEN_NONCANONICAL' => Option::get(CanonicalHandler::MODULE_ID, CanonicalHandler::OPT_PAGEN_NONCANONICAL, 'N', $siteId),
            'CANONICAL_STRIP_GET' => Option::get(CanonicalHandler::MODULE_ID, CanonicalHandler::OPT_STRIP_GET, 'N', $siteId),
            'CANONICAL_HTTPS' => Option::get(CanonicalHandler::MODULE_ID, CanonicalHandler::OPT_HTTPS, 'N', $siteId),
            'CANONICAL_INDEX_SLASH' => Option::get(CanonicalHandler::MODULE_ID, CanonicalHandler::OPT_INDEX_SLASH, 'Y', $siteId),
            'CANONICAL_SKIP_ERRORS' => Option::get(CanonicalHandler::MODULE_ID, CanonicalHandler::OPT_SKIP_ERRORS, 'Y', $siteId),
            'CANONICAL_OVERRIDE' => Option::get(CanonicalHandler::MODULE_ID, CanonicalHandler::OPT_OVERRIDE, 'N', $siteId),
            'CANONICAL_KEEP_GET' => Option::get(CanonicalHandler::MODULE_ID, CanonicalHandler::OPT_KEEP_GET, '', $siteId),
            'CANONICAL_REGEX_COUNT' => count($regexRules),
            'META_KEYWORDS_CLEAN' => Option::get(\Awz\Seo\Meta\Handler::MODULE_ID, \Awz\Seo\Meta\Handler::OPT_KEYWORDS_CLEAN, 'N', $siteId),
            'META_PAGEN_ENABLE' => Option::get(\Awz\Seo\Meta\Handler::MODULE_ID, \Awz\Seo\Meta\Handler::OPT_PAGEN_ENABLE, 'N', $siteId),
            'META_PAGEN_FROM' => Option::get(\Awz\Seo\Meta\Handler::MODULE_ID, \Awz\Seo\Meta\Handler::OPT_PAGEN_FROM, '2', $siteId),
            'META_PAGEN_TITLE_TEMPLATE' => Option::get(\Awz\Seo\Meta\Handler::MODULE_ID, \Awz\Seo\Meta\Handler::OPT_PAGEN_TITLE, '', $siteId),
            'META_PAGEN_DESCRIPTION_TEMPLATE' => Option::get(\Awz\Seo\Meta\Handler::MODULE_ID, \Awz\Seo\Meta\Handler::OPT_PAGEN_DESCRIPTION, '', $siteId),
            'META_PAGEN_PAGE_TITLE_TEMPLATE' => Option::get(\Awz\Seo\Meta\Handler::MODULE_ID, \Awz\Seo\Meta\Handler::OPT_PAGEN_PAGE_TITLE, '', $siteId),
        ];
    }

    /**
     * Формирует набор тест-кейсов по включённым опциям
     */
    public static function buildTests(string $host, array $options, array $paths = []): array
    {
        $multiSlash = $options['REDIRECT_MULTI_SLASH'] === 'Y';
        $slashEnd = $options['REDIRECT_SLASH_END'] === 'Y';
        $forceHttps = $options['REDIRECT_HTTPS'] === 'Y';
        $indexSlash = $options['REDIRECT_INDEX_SLASH'] === 'Y';
        $code = ($options['REDIRECT_CODE'] === '302') ? 302 : 301;
        //если включён https-редирект, базовые тесты дёргаем сразу с https, чтобы он не мешал
        $scheme = $forceHttps ? 'https' : 'http';

        $tests = [];

        //контрольная точка: корень не должен редиректиться (нет цикла редиректов)
        $tests[] = [
            'title' => 'control: home page without redirect',
            'url' => $scheme.'://'.$host.'/',
            'expect_status' => 0,
            'expect_location' => null,
            'expect_canonical' => self::expectCanonical($options, false),
        ];

        if ($forceHttps) {
            $tests[] = [
                'title' => 'http -> https',
                'url' => 'http://'.$host.'/',
                'expect_status' => $code,
                'expect_location' => 'https://'.$host.'/',
                'expect_canonical' => null,
            ];
        }

        if ($multiSlash) {
            $tests[] = self::makeRedirectTest(
                'multiple slashes',
                '/awz-seo-check//double///slash',
                $options, $host
            );
        }

        if ($slashEnd) {
            $tests[] = self::makeRedirectTest('add trailing slash', '/awz-seo-check/no-slash', $options, $host);
            $tests[] = self::makeRedirectTest('trailing slash before GET', '/awz-seo-check/no-slash-get?x=1&y=2', $options, $host);
            //файл с расширением трогать нельзя
            $tests[] = [
                'title' => 'control: file with extension must not get slash',
                'url' => $scheme.'://'.$host.'/awz-seo-check/file.html',
                'expect_status' => 0,
                'expect_location' => null,
                'expect_canonical' => null,
            ];
        }

        if ($indexSlash) {
            $tests[] = self::makeRedirectTest('index.php -> slash', '/index.php', $options, $host);
            $tests[] = self::makeRedirectTest('index.php with GET -> slash', '/index.php?a=b', $options, $host);
        }

        //canonical
        $mode = $options['CANONICAL_MODE'];
        if ($mode === 'A') {
            $tests[] = [
                'title' => 'canonical: always mode',
                'url' => $scheme.'://'.$host.'/',
                'expect_status' => 0,
                'expect_location' => null,
                'expect_canonical' => true,
            ];
        } elseif ($mode === 'G') {
            $tests[] = [
                'title' => 'canonical: get mode (with GET params)',
                'url' => $scheme.'://'.$host.'/?awzcheck=1',
                'expect_status' => 0,
                'expect_location' => null,
                'expect_canonical' => true,
            ];
            $tests[] = [
                'title' => 'canonical: get mode (without GET params)',
                'url' => $scheme.'://'.$host.'/',
                'expect_status' => 0,
                'expect_location' => null,
                'expect_canonical' => false,
            ];
        }

        //дополнительные пользовательские пути
        foreach ($paths as $p) {
            $p = (string)$p;
            if ($p === '' || !preg_match('#^/#', $p))
                continue;
            $tests[] = self::makeRedirectTest('custom: '.$p, $p, $options, $host);
        }

        return $tests;
    }

    /**
     * Тест редиректа пути: ожидаемый результат считается той же логикой,
     * что и в обработчике OnPageStart (RedirectHandler::normalizePath)
     */
    public static function makeRedirectTest(string $title, string $uri, array $options, string $host): array
    {
        $pos = strpos($uri, '?');
        $path = $pos === false ? $uri : substr($uri, 0, $pos);
        $query = $pos === false ? null : substr($uri, $pos + 1);

        $newPath = RedirectHandler::normalizePath(
            $path,
            $options['REDIRECT_MULTI_SLASH'] === 'Y',
            $options['REDIRECT_INDEX_SLASH'] === 'Y',
            $options['REDIRECT_SLASH_END'] === 'Y'
        );
        $newUri = $newPath.($query !== null ? '?'.$query : '');
        $scheme = $options['REDIRECT_HTTPS'] === 'Y' ? 'https' : 'http';

        if ($newUri === $path.($query !== null ? '?'.$query : '')) {
            //редиректа по правилам не происходит
            return [
                'title' => 'control: '.$title,
                'url' => $scheme.'://'.$host.$newUri,
                'expect_status' => 0,
                'expect_location' => null,
                'expect_canonical' => null,
            ];
        }

        return [
            'title' => $title,
            'url' => $scheme.'://'.$host.$path.($query !== null ? '?'.$query : ''),
            'expect_status' => ($options['REDIRECT_CODE'] === '302') ? 302 : 301,
            'expect_location' => $scheme.'://'.$host.$newUri,
            'expect_canonical' => null,
        ];
    }

    /**
     * Ожидание canonical для контрольного теста:
     * N - выключен, A - всегда (canonical есть на любой странице),
     * G - только при наличии GET-параметров (ожидание = $default)
     */
    public static function expectCanonical(array $options, bool $default): ?bool
    {
        $mode = $options['CANONICAL_MODE'];
        if ($mode === 'N')
            return false;
        if ($mode === 'A')
            return true;
        return $default;
    }
}
