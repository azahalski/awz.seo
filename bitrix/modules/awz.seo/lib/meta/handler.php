<?php
namespace Awz\Seo\Meta;

use Bitrix\Main\Application;
use Bitrix\Main\Config\Option;
use Bitrix\Main\HttpRequest;

/**
 * Работа с meta-тегами на событии main::OnEpilog (ядро D7).
 * Настройки хранятся per-site (многосайтовость): Option::get($moduleId, $name, $default, SITE_ID)
 */
class Handler
{
    public const MODULE_ID = 'awz.seo';

    /** очищать meta keywords - устанавливать тег пустым */
    public const OPT_KEYWORDS_CLEAN = 'META_KEYWORDS_CLEAN';
    /** включать обработку страниц пагинации */
    public const OPT_PAGEN_ENABLE = 'META_PAGEN_ENABLE';
    /** с какой страницы пагинации начинать добавлять пометку (обычно 2) */
    public const OPT_PAGEN_FROM = 'META_PAGEN_FROM';
    /** шаблон с макросом #PAGE# для <title> ($APPLICATION->SetTitle) */
    public const OPT_PAGEN_TITLE = 'META_PAGEN_TITLE_TEMPLATE';
    /** шаблон с макросом #PAGE# для meta description */
    public const OPT_PAGEN_DESCRIPTION = 'META_PAGEN_DESCRIPTION_TEMPLATE';
    /** шаблон с макросом #PAGE# для заголовка страницы (свойство PAGE_TITLE, обычно используется в H1) */
    public const OPT_PAGEN_PAGE_TITLE = 'META_PAGEN_PAGE_TITLE_TEMPLATE';

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

        $keywordsClean = Option::get(self::MODULE_ID, self::OPT_KEYWORDS_CLEAN, 'N', $siteId) === 'Y';
        $pagenEnable = Option::get(self::MODULE_ID, self::OPT_PAGEN_ENABLE, 'N', $siteId) === 'Y';

        if (!$keywordsClean && !$pagenEnable)
            return;

        //1) очистка meta keywords - устанавливаем тег пустым
        if ($keywordsClean && (string)$APPLICATION->GetProperty('keywords', '') !== '') {
            $APPLICATION->SetPageProperty('keywords', '');
        }

        //2) страницы пагинации: добавляем номер страницы в title, description и заголовок страницы
        if ($pagenEnable) {
            $pageNum = self::getPageNumber($request);
            $from = (int)Option::get(self::MODULE_ID, self::OPT_PAGEN_FROM, '2', $siteId);
            if ($from < 2)
                $from = 2;
            if ($pageNum >= $from) {
                self::applyPagenTemplates($APPLICATION, $siteId, $pageNum);
            }
        }
    }

    /**
     * Номер текущей страницы пагинации по параметрам PAGEN_N (максимальный из присутствующих)
     */
    protected static function getPageNumber(HttpRequest $request): int
    {
        $pageNum = 0;
        $get = $request->getQueryList();
        foreach ($get as $key => $value) {
            if (!preg_match('#^pagen_\d+$#i', (string)$key))
                continue;
            $num = (int)(is_array($value) ? reset($value) : $value);
            if ($num > $pageNum)
                $pageNum = $num;
        }
        return $pageNum;
    }

    /**
     * Подставляет шаблоны #PAGE# в title, description и PAGE_TITLE
     */
    protected static function applyPagenTemplates($APPLICATION, string $siteId, int $pageNum): void
    {
        $titleTpl = (string)Option::get(self::MODULE_ID, self::OPT_PAGEN_TITLE, '', $siteId);
        $descTpl = (string)Option::get(self::MODULE_ID, self::OPT_PAGEN_DESCRIPTION, '', $siteId);
        $pageTitleTpl = (string)Option::get(self::MODULE_ID, self::OPT_PAGEN_PAGE_TITLE, '', $siteId);

        if ($titleTpl !== '') {
            $title = (string)$APPLICATION->GetTitle();
            if ($title !== '' && mb_stripos($title, '#PAGE#') === false) {
                //GetTitle()/ShowTitle() отдаёт значение без экранирования (<title> - RCDATA),
                //поэтому шаблон из настройки экранируем при добавлении
                $APPLICATION->SetTitle($title.' '.htmlspecialcharsbx(self::replaceMacro($titleTpl, $pageNum)));
            }
        }

        if ($descTpl !== '') {
            $description = (string)$APPLICATION->GetProperty('description', '');
            if ($description !== '') {
                $APPLICATION->SetPageProperty(
                    'description',
                    $description.' '.self::replaceMacro($descTpl, $pageNum)
                );
            }
        }

        if ($pageTitleTpl !== '') {
            $pageTitle = (string)$APPLICATION->GetProperty('PAGE_TITLE', '');
            if ($pageTitle !== '' && mb_stripos($pageTitle, '#PAGE#') === false) {
                $APPLICATION->SetPageProperty(
                    'PAGE_TITLE',
                    $pageTitle.' '.self::replaceMacro($pageTitleTpl, $pageNum)
                );
            }
        }
    }

    protected static function replaceMacro(string $template, int $pageNum): string
    {
        if (strpos($template, '#PAGE#') !== false)
            return str_replace('#PAGE#', (string)$pageNum, $template);
        //если макрос забыли - добавляем номер в конец шаблона
        return $template.' '.$pageNum;
    }
}
