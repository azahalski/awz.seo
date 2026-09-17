<?php
require_once($_SERVER["DOCUMENT_ROOT"]."/bitrix/modules/main/include/prolog_admin_before.php");

use Bitrix\Main\Localization\Loc;
use Bitrix\Main\Config\Option;
use Bitrix\Main\Loader;
use Bitrix\Main\Application;
use Bitrix\Main\SiteTable;
use Bitrix\Main\UI\Extension;
use Awz\Seo\Access\AccessController;
use Awz\Seo\Checker\Checker;
use Awz\Seo\Redirect\Handler as RedirectHandler;
use Awz\Seo\Canonical\Handler as CanonicalHandler;
use Awz\Seo\Meta\Handler as MetaHandler;

/**
 * Разбирает содержимое CSV с парами "старый URL, новый URL".
 * Разделитель - запятая или точка с запятой (определяется автоматически по первой строке).
 * "Старый URL" может быть полным (с https:// и хостом) или путём.
 * @param string $content содержимое CSV-файла
 * @return array массив [['FROM'=>..,'TO'=>..], ...]
 */
function awz_seo_parse_csv_content(string $content): array
{
    if (trim($content) === '')
        return [];

    //убираем BOM
    $content = (string)preg_replace('/^\xEF\xBB\xBF/', '', $content);

    //определяем разделитель по первой строке: запятая или точка с запятой
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

/**
 * Читает CSV-файл по пути (относительно DOCUMENT_ROOT) и разбирает его.
 * @param string $relativePath путь к файлу (например, /upload/redirects.csv)
 * @return array массив [['FROM'=>..,'TO'=>..], ...]
 */
function awz_seo_read_csv_from_path(string $relativePath): array
{
    $relativePath = trim($relativePath);
    if ($relativePath === '')
        return [];
    //санитизация: убираем ".." и нормализуем путь
    $relativePath = str_replace(['..', '\\'], '', $relativePath);
    $relativePath = ltrim($relativePath, '/');
    if ($relativePath === '')
        return [];
    $fullPath = $_SERVER['DOCUMENT_ROOT'].'/'.$relativePath;
    //проверка, что путь не выходит за пределы DOCUMENT_ROOT
    $realPath = realpath($fullPath);
    $docRoot = realpath($_SERVER['DOCUMENT_ROOT']);
    if ($realPath === false || $docRoot === false)
        return [];
    if (strpos($realPath, $docRoot) !== 0)
        return [];
    if (!is_file($realPath))
        return [];
    $content = @file_get_contents($realPath);
    if ($content === false)
        return [];
    return awz_seo_parse_csv_content($content);
}

Loc::loadMessages(__FILE__);
global $APPLICATION;
$module_id = "awz.seo";
if(!Loader::includeModule($module_id)) return;
Extension::load('ui.sidepanel-content');
$request = Application::getInstance()->getContext()->getRequest();
$APPLICATION->SetTitle(Loc::getMessage('AWZ_SEO_OPT_TITLE'));

if($request->get('IFRAME_TYPE')==='SIDE_SLIDER'){
    require($_SERVER["DOCUMENT_ROOT"]."/bitrix/modules/main/include/prolog_admin_after.php");
    require_once('lib/access/include/moduleright.php');
    CMain::finalActions();
    die();
}

if(!AccessController::isViewSettings())
    $APPLICATION->AuthForm(Loc::getMessage("ACCESS_DENIED"));
require($_SERVER["DOCUMENT_ROOT"]."/bitrix/modules/main/include/prolog_admin_after.php");

$siteRes = SiteTable::getList(['select'=>['LID','NAME'],'filter'=>['ACTIVE'=>'Y']])->fetchAll();

//опции редиректов (per-site)
$redirectOptions = [
    RedirectHandler::OPT_MULTI_SLASH,
    RedirectHandler::OPT_SLASH_END,
    RedirectHandler::OPT_HTTPS,
    RedirectHandler::OPT_INDEX_SLASH,
];
//опции canonical (per-site)
$canonicalCheckboxOptions = [
    CanonicalHandler::OPT_PAGEN_NONCANONICAL,
    CanonicalHandler::OPT_STRIP_GET,
    CanonicalHandler::OPT_HTTPS,
    CanonicalHandler::OPT_INDEX_SLASH,
    CanonicalHandler::OPT_SKIP_ERRORS,
    CanonicalHandler::OPT_OVERRIDE,
];

if ($request->getRequestMethod()==='POST' && AccessController::isEditSettings() && $request->get('Update') && check_bitrix_sessid())
{
    $postSiteId = (string)$request->get('SITE_ID');
    foreach($siteRes as $arSite){
        if($arSite['LID'] !== $postSiteId) continue;
        $lid = $arSite['LID'];
        //редиректы
        $redirectPost = $request->getPost('REDIRECT') ?: [];
        if(!is_array($redirectPost)) $redirectPost = [];
        foreach($redirectOptions as $opt){
            Option::set($module_id, $opt, (($redirectPost[$opt] ?? '') === 'Y') ? 'Y' : 'N', $lid);
        }
        $code = (string)($redirectPost['CODE'] ?? '301');
        Option::set($module_id, RedirectHandler::OPT_CODE, ($code === '302') ? '302' : '301', $lid);
        $www = (string)($redirectPost['WWW'] ?? 'N');
        if(!in_array($www, ['N','W','NW'], true)) $www = 'N';
        Option::set($module_id, RedirectHandler::OPT_WWW, $www, $lid);
        $csvPath = trim((string)($redirectPost['CSV_PATH'] ?? ''));
        Option::set($module_id, RedirectHandler::OPT_CUSTOM, $csvPath, $lid);
        //canonical
        $canonicalPost = $request->getPost('CANONICAL') ?: [];
        if(!is_array($canonicalPost)) $canonicalPost = [];
        $mode = (string)($canonicalPost['MODE'] ?? 'N');
        if(!in_array($mode, ['N','A','G'], true)) $mode = 'N';
        Option::set($module_id, CanonicalHandler::OPT_MODE, $mode, $lid);
        foreach($canonicalCheckboxOptions as $opt){
            $shortKey = str_replace('CANONICAL_', '', $opt);
            Option::set($module_id, $opt, (($canonicalPost[$shortKey] ?? '') === 'Y') ? 'Y' : 'N', $lid);
        }
        $keepGet = trim((string)($canonicalPost['KEEP_GET'] ?? ''));
        Option::set($module_id, CanonicalHandler::OPT_KEEP_GET, $keepGet, $lid);
        //множественные правила preg_replace from => to
        $regexRules = [];
        $regexFrom = (isset($canonicalPost['REGEX']['FROM']) && is_array($canonicalPost['REGEX']['FROM'])) ? $canonicalPost['REGEX']['FROM'] : [];
        $regexTo = (isset($canonicalPost['REGEX']['TO']) && is_array($canonicalPost['REGEX']['TO'])) ? $canonicalPost['REGEX']['TO'] : [];
        foreach($regexFrom as $idx => $from){
            $from = trim((string)$from);
            if($from === '') continue;
            $regexRules[] = ['FROM' => $from, 'TO' => trim((string)($regexTo[$idx] ?? ''))];
            if(count($regexRules) >= CanonicalHandler::REGEX_MAX_RULES) break;
        }
        Option::set($module_id, CanonicalHandler::OPT_REGEX, !empty($regexRules) ? serialize($regexRules) : '', $lid);
        //meta теги
        $metaPost = $request->getPost('META') ?: [];
        if(!is_array($metaPost)) $metaPost = [];
        Option::set($module_id, MetaHandler::OPT_KEYWORDS_CLEAN, (($metaPost['KEYWORDS_CLEAN'] ?? '') === 'Y') ? 'Y' : 'N', $lid);
        Option::set($module_id, MetaHandler::OPT_PAGEN_ENABLE, (($metaPost['PAGEN_ENABLE'] ?? '') === 'Y') ? 'Y' : 'N', $lid);
        Option::set($module_id, MetaHandler::OPT_ROBOTS_DEFAULT, (($metaPost['ROBOTS_DEFAULT'] ?? '') === 'Y') ? 'Y' : 'N', $lid);
        Option::set($module_id, MetaHandler::OPT_ROBOTS_NOINDEX, (($metaPost['ROBOTS_NOINDEX'] ?? '') === 'Y') ? 'Y' : 'N', $lid);
        Option::set($module_id, MetaHandler::OPT_PAGEN_FROM, max(2, (int)($metaPost['PAGEN_FROM'] ?? 2)), $lid);
        Option::set($module_id, MetaHandler::OPT_PAGEN_TITLE, trim((string)($metaPost['PAGEN_TITLE_TEMPLATE'] ?? '')), $lid);
        Option::set($module_id, MetaHandler::OPT_PAGEN_DESCRIPTION, trim((string)($metaPost['PAGEN_DESCRIPTION_TEMPLATE'] ?? '')), $lid);
        Option::set($module_id, MetaHandler::OPT_PAGEN_PAGE_TITLE, trim((string)($metaPost['PAGEN_PAGE_TITLE_TEMPLATE'] ?? '')), $lid);
        break;
    }
    LocalRedirect($APPLICATION->GetCurPage(false).'?mid='.htmlspecialcharsbx($module_id).'&lang='.LANGUAGE_ID.'&mid_menu=1&SITE_ID='.urlencode((string)$request->get('SITE_ID')).'&saved=Y');
}

$saveUrl = $APPLICATION->GetCurPage(false).'?mid='.htmlspecialcharsbx($module_id).'&lang='.LANGUAGE_ID.'&mid_menu=1';
$currentSite = (string)($request->get('SITE_ID') ?: (isset($siteRes[0]['LID']) ? $siteRes[0]['LID'] : ''));
$canEdit = AccessController::isEditSettings();
$disabledAttr = $canEdit ? '' : ' disabled';

//данные для внешнего сервиса проверки
$checkHost = (string)($_SERVER['HTTP_HOST'] ?? '');
$checkOptions = Checker::getSiteOptions($currentSite);
$checkPayload = json_encode([
    'site' => ['ID' => $currentSite],
    'host' => $checkHost,
    'options' => $checkOptions,
    'tests' => Checker::buildTests($checkHost, $checkOptions),
], JSON_UNESCAPED_UNICODE);
$checkServiceUrl = 'https://zahalski.dev/awz.seo/';

$ext = Extension::load("ui.alerts");
?>
<style>
    .awz-seo-page{max-width:1280px;}
    .awz-seo-card{
        background:#fff;border:1px solid #e5e8ec;border-radius:12px;
        box-shadow:0 1px 2px rgba(16,24,40,.05);margin:0 0 16px;overflow:hidden;
    }
    .awz-seo-card__head{padding:16px 20px 12px;border-bottom:1px solid #eef0f3;}
    .awz-seo-card__title{font-size:15px;font-weight:600;color:#1d2433;margin:0;}
    .awz-seo-card__sub{font-size:12px;color:#8a94a6;margin:3px 0 0;}
    .awz-seo-row{
        display:flex;align-items:flex-start;gap:16px;
        padding:13px 20px;border-bottom:1px solid #f2f4f7;
    }
    .awz-seo-row:last-child{border-bottom:none;}
    .awz-seo-row__label{width:250px;flex:0 0 250px;font-size:13px;font-weight:500;color:#2a3346;padding-top:3px;}
    .awz-seo-row__ctrl{flex:1 1 auto;min-width:0;}
    .awz-seo-row__desc{font-size:12px;line-height:1.45;color:#8a94a6;margin-top:5px;font-weight:normal;}
    .awz-seo-input,.awz-seo-select{
        border:1px solid #d7dce3;border-radius:8px;padding:7px 10px;font-size:13px;
        background:#fff;color:#1d2433;transition:border-color .15s, box-shadow .15s;max-width:100%;
        box-sizing:border-box;
    }
    .awz-seo-input:focus,.awz-seo-select:focus{outline:none;border-color:#2f9cf6;box-shadow:0 0 0 3px rgba(47,156,246,.15);}
    .awz-seo-input[disabled],.awz-seo-select[disabled]{background:#f5f6f8;color:#9aa3b2;}
    .awz-seo-input--w{width:100%;max-width:460px;}
    .awz-seo-input--sm{width:80px;text-align:center;}
    /* переключатель */
    .awz-switch{position:relative;display:inline-block;width:40px;height:22px;vertical-align:middle;}
    .awz-switch input{position:absolute;opacity:0;width:100%;height:100%;margin:0;cursor:pointer;z-index:1;}
    .awz-switch input:disabled{cursor:default;}
    .awz-switch__slider{
        position:absolute;inset:0;background:#cfd6de;border-radius:22px;transition:.18s;
    }
    .awz-switch__slider:before{
        content:"";position:absolute;height:16px;width:16px;left:3px;top:3px;
        background:#fff;border-radius:50%;transition:.18s;box-shadow:0 1px 2px rgba(16,24,40,.3);
    }
    .awz-switch input:checked + .awz-switch__slider{background:#2f9cf6;}
    .awz-switch input:checked + .awz-switch__slider:before{transform:translateX(18px);}
    .awz-switch input:disabled + .awz-switch__slider{opacity:.45;}
    .awz-switch input:focus-visible + .awz-switch__slider{box-shadow:0 0 0 3px rgba(47,156,246,.3);}
    /* кнопки */
    .awz-seo-btn{
        display:inline-flex;align-items:center;gap:6px;border-radius:8px;padding:9px 18px;
        font-size:13px;font-weight:600;text-decoration:none;cursor:pointer;border:1px solid transparent;
        transition:filter .15s;
    }
    .awz-seo-btn--primary{background:#2f9cf6;color:#fff;}
    .awz-seo-btn--primary:hover{filter:brightness(1.06);color:#fff;}
    .awz-seo-btn--ghost{background:#fff;border-color:#d7dce3;color:#2a3346;}
    .awz-seo-btn--ghost:hover{border-color:#2f9cf6;color:#2f9cf6;}
    .awz-seo-footer{display:flex;align-items:center;gap:10px;margin:18px 0;}
    /* правила regex */
    .awz-regex-row{display:flex;gap:8px;margin-bottom:8px;flex-wrap:wrap;}
    .awz-regex-row .awz-seo-input{flex:1 1 200px;}
    .awz-regex-del{
        border:1px solid #d7dce3;background:#fff;border-radius:8px;color:#8a94a6;
        font-size:15px;line-height:1;padding:0 11px;cursor:pointer;
    }
    .awz-regex-del:hover{border-color:#e05252;color:#e05252;}
    .awz-seo-check{
        background:linear-gradient(135deg,#f0f7ff 0%,#eefcf4 100%);
        border:1px solid #dbeafe;border-radius:12px;padding:16px 20px;margin:0 0 16px;
        display:flex;align-items:center;gap:16px;flex-wrap:wrap;
    }
</style>
<div class="awz-seo-page">
    <div class="ui-alert ui-alert-primary" style="margin-bottom:16px;">
        <span class="ui-alert-message"><?=Loc::getMessage('AWZ_SEO_OPT_SHOW_DESC')?></span>
    </div>

    <form method="POST" action="<?=$saveUrl?>" id="FORMACTION">
        <?=bitrix_sessid_post()?>
        <input type="hidden" name="SITE_ID" value="<?=$currentSite?>"/>

        <div class="awz-seo-card">
            <div class="awz-seo-card__head">
                <h2 class="awz-seo-card__title"><?=Loc::getMessage('AWZ_SEO_OPT_SECT1')?></h2>
                <p class="awz-seo-card__sub"><?=Loc::getMessage('AWZ_SEO_OPT_SITE_SUB')?></p>
            </div>
            <div class="awz-seo-row">
                <div class="awz-seo-row__label"><?=Loc::getMessage('AWZ_SEO_OPT_SITE_ID')?></div>
                <div class="awz-seo-row__ctrl">
                    <select class="awz-seo-select" onchange="window.location.href=this.value;">
                        <?foreach($siteRes as $arSite){?>
                            <option value="<?=$saveUrl?>&SITE_ID=<?=$arSite['LID']?>"<?if($arSite['LID']==$currentSite){?> selected="selected"<?}?>>
                                [<?=$arSite['LID']?>] - <?=$arSite['NAME']?>
                            </option>
                        <?}?>
                    </select>
                </div>
            </div>
        </div>

        <?
        $val = Option::get($module_id, RedirectHandler::OPT_MULTI_SLASH, "N", $currentSite);
        $val2 = Option::get($module_id, RedirectHandler::OPT_SLASH_END, "N", $currentSite);
        $val3 = Option::get($module_id, RedirectHandler::OPT_HTTPS, "N", $currentSite);
        $val4 = Option::get($module_id, RedirectHandler::OPT_INDEX_SLASH, "N", $currentSite);
        $valCode = Option::get($module_id, RedirectHandler::OPT_CODE, "301", $currentSite);
        $valWww = Option::get($module_id, RedirectHandler::OPT_WWW, "N", $currentSite);
        $valCsvPath = Option::get($module_id, RedirectHandler::OPT_CUSTOM, "", $currentSite);
        $customRedirectsCount = count(RedirectHandler::getCustomRedirects($currentSite));
        ?>
        <div class="awz-seo-card">
            <div class="awz-seo-card__head">
                <h2 class="awz-seo-card__title"><?=Loc::getMessage('AWZ_SEO_OPT_REDIRECT_HEAD')?></h2>
                <p class="awz-seo-card__sub"><?=Loc::getMessage('AWZ_SEO_OPT_REDIRECT_SUB')?></p>
            </div>

            <?
            $redirectRows = [
                [RedirectHandler::OPT_MULTI_SLASH, 'AWZ_SEO_OPT_MULTI_SLASH', 'AWZ_SEO_OPT_MULTI_SLASH_DESC', $val],
                [RedirectHandler::OPT_SLASH_END, 'AWZ_SEO_OPT_SLASH_END', 'AWZ_SEO_OPT_SLASH_END_DESC', $val2],
                [RedirectHandler::OPT_HTTPS, 'AWZ_SEO_OPT_HTTPS_REDIRECT', 'AWZ_SEO_OPT_HTTPS_REDIRECT_DESC', $val3],
                [RedirectHandler::OPT_INDEX_SLASH, 'AWZ_SEO_OPT_INDEX_SLASH', 'AWZ_SEO_OPT_INDEX_SLASH_DESC', $val4],
            ];
            foreach($redirectRows as $row){
                ?>
                <div class="awz-seo-row">
                    <div class="awz-seo-row__label"><?=Loc::getMessage($row[1])?></div>
                    <div class="awz-seo-row__ctrl">
                        <label class="awz-switch">
                            <input type="checkbox" value="Y" name="REDIRECT[<?=$row[0]?>]" <?if($row[3]=="Y") echo "checked";?><?=$disabledAttr?>>
                            <span class="awz-switch__slider"></span>
                        </label>
                        <div class="awz-seo-row__desc"><?=Loc::getMessage($row[2])?></div>
                    </div>
                </div>
                <?
            }
            ?>
            <div class="awz-seo-row">
                <div class="awz-seo-row__label"><?=Loc::getMessage('AWZ_SEO_OPT_REDIRECT_CODE')?></div>
                <div class="awz-seo-row__ctrl">
                    <select class="awz-seo-select" name="REDIRECT[CODE]"<?=$disabledAttr?>>
                        <option value="301"<?=($valCode==='302')?'':' selected="selected"'?>>301 (<?=Loc::getMessage('AWZ_SEO_OPT_REDIRECT_CODE_PERM')?>)</option>
                        <option value="302"<?=($valCode==='302')?' selected="selected"':''?>>302 (<?=Loc::getMessage('AWZ_SEO_OPT_REDIRECT_CODE_TEMP')?>)</option>
                    </select>
                    <div class="awz-seo-row__desc"><?=Loc::getMessage('AWZ_SEO_OPT_REDIRECT_CODE_DESC')?></div>
                </div>
            </div>

            <div class="awz-seo-row">
                <div class="awz-seo-row__label"><?=Loc::getMessage('AWZ_SEO_OPT_WWW_REDIRECT')?></div>
                <div class="awz-seo-row__ctrl">
                    <select class="awz-seo-select" name="REDIRECT[WWW]"<?=$disabledAttr?>>
                        <option value="N"<?=($valWww==='N')?' selected="selected"':''?>><?=Loc::getMessage('AWZ_SEO_OPT_WWW_N')?></option>
                        <option value="W"<?=($valWww==='W')?' selected="selected"':''?>><?=Loc::getMessage('AWZ_SEO_OPT_WWW_W')?></option>
                        <option value="NW"<?=($valWww==='NW')?' selected="selected"':''?>><?=Loc::getMessage('AWZ_SEO_OPT_WWW_NW')?></option>
                    </select>
                    <div class="awz-seo-row__desc"><?=Loc::getMessage('AWZ_SEO_OPT_WWW_REDIRECT_DESC')?></div>
                </div>
            </div>

            <div class="awz-seo-row">
                <div class="awz-seo-row__label"><?=Loc::getMessage('AWZ_SEO_OPT_CUSTOM_CSV_FILE')?></div>
                <div class="awz-seo-row__ctrl">
                    <input type="text" name="REDIRECT[CSV_PATH]" id="AWZ_SEO_CSV_FILE_PATH" value="<?=htmlspecialcharsbx($valCsvPath)?>" class="awz-seo-input" style="width:320px;" placeholder="/upload/redirects.csv"<?=$disabledAttr?>>
                    <?\CAdminFileDialog::ShowScript(array(
                        "event" => "AWZ_SEO_CSV_FILE_PATH",
                        "arResultDest" => array("ELEMENT_ID" => "AWZ_SEO_CSV_FILE_PATH"),
                        "arPath" => array("PATH" => "/upload/"),
                        "select" => 'F',
                        "operation" => 'O',
                        "showUploadTab" => true,
                        "showAddToMenuTab" => false,
                        "fileFilter" => 'csv',
                        "allowAllFiles" => false,
                        "SaveConfig" => true,
                    ));?>
                    <input type="button" value="..." onClick="window.AWZ_SEO_CSV_FILE_PATH()">
                    <div class="awz-seo-row__desc"><?=Loc::getMessage('AWZ_SEO_OPT_CUSTOM_CSV_DESC')?></div>
                </div>
            </div>

        </div>

        <?
        $canonMode = Option::get($module_id, CanonicalHandler::OPT_MODE, "N", $currentSite);
        $canonicalFields = [
            CanonicalHandler::OPT_PAGEN_NONCANONICAL => 'PAGEN_NONCANONICAL',
            CanonicalHandler::OPT_STRIP_GET => 'STRIP_GET',
            CanonicalHandler::OPT_HTTPS => 'HTTPS',
            CanonicalHandler::OPT_INDEX_SLASH => 'INDEX_SLASH',
            CanonicalHandler::OPT_SKIP_ERRORS => 'SKIP_ERRORS',
            CanonicalHandler::OPT_OVERRIDE => 'OVERRIDE',
        ];
        ?>
        <div class="awz-seo-card">
            <div class="awz-seo-card__head">
                <h2 class="awz-seo-card__title"><?=Loc::getMessage('AWZ_SEO_OPT_CANONICAL_HEAD')?></h2>
                <p class="awz-seo-card__sub"><?=Loc::getMessage('AWZ_SEO_OPT_CANONICAL_SUB')?></p>
            </div>

            <div class="awz-seo-row">
                <div class="awz-seo-row__label"><?=Loc::getMessage('AWZ_SEO_OPT_CANONICAL_MODE')?></div>
                <div class="awz-seo-row__ctrl">
                    <select class="awz-seo-select" name="CANONICAL[MODE]"<?=$disabledAttr?>>
                        <option value="N"<?=($canonMode==='N')?' selected="selected"':''?>><?=Loc::getMessage('AWZ_SEO_OPT_CANONICAL_MODE_N')?></option>
                        <option value="A"<?=($canonMode==='A')?' selected="selected"':''?>><?=Loc::getMessage('AWZ_SEO_OPT_CANONICAL_MODE_A')?></option>
                        <option value="G"<?=($canonMode==='G')?' selected="selected"':''?>><?=Loc::getMessage('AWZ_SEO_OPT_CANONICAL_MODE_G')?></option>
                    </select>
                    <div class="awz-seo-row__desc"><?=Loc::getMessage('AWZ_SEO_OPT_CANONICAL_MODE_DESC')?></div>
                </div>
            </div>

            <?
            foreach($canonicalFields as $optName => $postKey){
                $valC = Option::get($module_id, $optName, ($optName===CanonicalHandler::OPT_SKIP_ERRORS ? 'Y' : 'N'), $currentSite);
                ?>
                <div class="awz-seo-row">
                    <div class="awz-seo-row__label"><?=Loc::getMessage('AWZ_SEO_OPT_CANONICAL_'.$postKey)?></div>
                    <div class="awz-seo-row__ctrl">
                        <label class="awz-switch">
                            <input type="checkbox" value="Y" name="CANONICAL[<?=$postKey?>]" <?if($valC=="Y") echo "checked";?><?=$disabledAttr?>>
                            <span class="awz-switch__slider"></span>
                        </label>
                        <div class="awz-seo-row__desc"><?=Loc::getMessage('AWZ_SEO_OPT_CANONICAL_'.$postKey.'_DESC')?></div>
                    </div>
                </div>
                <?
            }
            $keepGetVal = Option::get($module_id, CanonicalHandler::OPT_KEEP_GET, "", $currentSite);
            ?>
            <div class="awz-seo-row">
                <div class="awz-seo-row__label"><?=Loc::getMessage('AWZ_SEO_OPT_CANONICAL_KEEP_GET')?></div>
                <div class="awz-seo-row__ctrl">
                    <input class="awz-seo-input awz-seo-input--w" type="text" value="<?=htmlspecialcharsbx($keepGetVal)?>" placeholder="utm_source, utm_medium" name="CANONICAL[KEEP_GET]"<?=$disabledAttr?>>
                    <div class="awz-seo-row__desc"><?=Loc::getMessage('AWZ_SEO_OPT_CANONICAL_KEEP_GET_DESC')?></div>
                </div>
            </div>

            <?
            $regexRulesList = unserialize(
                (string)Option::get($module_id, CanonicalHandler::OPT_REGEX, "", $currentSite),
                ['allowed_classes'=>false]
            );
            if(!is_array($regexRulesList)) $regexRulesList = [];
            ?>
            <div class="awz-seo-row">
                <div class="awz-seo-row__label"><?=Loc::getMessage('AWZ_SEO_OPT_CANONICAL_REGEX')?></div>
                <div class="awz-seo-row__ctrl">
                    <div id="awz_canonical_regex_list">
                        <?if(empty($regexRulesList)){?>
                            <div class="awz-regex-row">
                                <input class="awz-seo-input" type="text" placeholder="from (#...#i)" name="CANONICAL[REGEX][FROM][]" value=""<?=$disabledAttr?>>
                                <input class="awz-seo-input" type="text" placeholder="to" name="CANONICAL[REGEX][TO][]" value=""<?=$disabledAttr?>>
                            </div>
                        <?}else{?>
                            <?foreach($regexRulesList as $rule){?>
                                <div class="awz-regex-row">
                                    <input class="awz-seo-input" type="text" placeholder="from (#...#i)" name="CANONICAL[REGEX][FROM][]" value="<?=htmlspecialcharsbx($rule['FROM'] ?? '')?>"<?=$disabledAttr?>>
                                    <input class="awz-seo-input" type="text" placeholder="to" name="CANONICAL[REGEX][TO][]" value="<?=htmlspecialcharsbx($rule['TO'] ?? '')?>"<?=$disabledAttr?>>
                                    <button type="button" class="awz-regex-del" title="удалить" onclick="this.parentNode.remove();return false;"<?=$disabledAttr?>>×</button>
                                </div>
                            <?}?>
                        <?}?>
                    </div>
                    <template id="awz_canonical_regex_tpl">
                        <div class="awz-regex-row">
                            <input class="awz-seo-input" type="text" placeholder="from (#...#i)" name="CANONICAL[REGEX][FROM][]" value="">
                            <input class="awz-seo-input" type="text" placeholder="to" name="CANONICAL[REGEX][TO][]" value="">
                            <button type="button" class="awz-regex-del" title="удалить" onclick="this.parentNode.remove();return false;">×</button>
                        </div>
                    </template>
                    <?if($canEdit){?>
                        <button type="button" class="awz-seo-btn awz-seo-btn--ghost" onclick="var l=document.getElementById('awz_canonical_regex_list'),t=document.getElementById('awz_canonical_regex_tpl');l.appendChild(t.content.cloneNode(true));return false;">
                            + <?=Loc::getMessage('AWZ_SEO_OPT_CANONICAL_REGEX_ADD')?>
                        </button>
                    <?}?>
                    <div class="awz-seo-row__desc"><?=Loc::getMessage('AWZ_SEO_OPT_CANONICAL_REGEX_DESC')?></div>
                </div>
            </div>
        </div>

        <?
        $valKwClean = Option::get($module_id, MetaHandler::OPT_KEYWORDS_CLEAN, "N", $currentSite);
        $valPagenEnable = Option::get($module_id, MetaHandler::OPT_PAGEN_ENABLE, "N", $currentSite);
        $valRobotsDefault = Option::get($module_id, MetaHandler::OPT_ROBOTS_DEFAULT, "N", $currentSite);
        $valRobotsNoindex = Option::get($module_id, MetaHandler::OPT_ROBOTS_NOINDEX, "N", $currentSite);
        $valPagenFrom = (int)Option::get($module_id, MetaHandler::OPT_PAGEN_FROM, "2", $currentSite);
        $valPagenTitle = Option::get($module_id, MetaHandler::OPT_PAGEN_TITLE, "", $currentSite);
        $valPagenDesc = Option::get($module_id, MetaHandler::OPT_PAGEN_DESCRIPTION, "", $currentSite);
        $valPagenPageTitle = Option::get($module_id, MetaHandler::OPT_PAGEN_PAGE_TITLE, "", $currentSite);
        ?>
        <div class="awz-seo-card">
            <div class="awz-seo-card__head">
                <h2 class="awz-seo-card__title"><?=Loc::getMessage('AWZ_SEO_OPT_META_HEAD')?></h2>
                <p class="awz-seo-card__sub"><?=Loc::getMessage('AWZ_SEO_OPT_META_SUB')?></p>
            </div>

            <div class="awz-seo-row">
                <div class="awz-seo-row__label"><?=Loc::getMessage('AWZ_SEO_OPT_META_KEYWORDS_CLEAN')?></div>
                <div class="awz-seo-row__ctrl">
                    <label class="awz-switch">
                        <input type="checkbox" value="Y" name="META[KEYWORDS_CLEAN]" <?if($valKwClean=="Y") echo "checked";?><?=$disabledAttr?>>
                        <span class="awz-switch__slider"></span>
                    </label>
                    <div class="awz-seo-row__desc"><?=Loc::getMessage('AWZ_SEO_OPT_META_KEYWORDS_CLEAN_DESC')?></div>
                </div>
            </div>

            <div class="awz-seo-row">
                <div class="awz-seo-row__label"><?=Loc::getMessage('AWZ_SEO_OPT_META_PAGEN_ENABLE')?></div>
                <div class="awz-seo-row__ctrl">
                    <label class="awz-switch">
                        <input type="checkbox" value="Y" name="META[PAGEN_ENABLE]" <?if($valPagenEnable=="Y") echo "checked";?><?=$disabledAttr?>>
                        <span class="awz-switch__slider"></span>
                    </label>
                    <div class="awz-seo-row__desc"><?=Loc::getMessage('AWZ_SEO_OPT_META_PAGEN_ENABLE_DESC')?></div>
                </div>
            </div>

            <div class="awz-seo-row">
                <div class="awz-seo-row__label"><?=Loc::getMessage('AWZ_SEO_OPT_META_PAGEN_FROM')?></div>
                <div class="awz-seo-row__ctrl">
                    <input class="awz-seo-input awz-seo-input--sm" type="text" value="<?=$valPagenFrom?>" name="META[PAGEN_FROM]"<?=$disabledAttr?>>
                    <div class="awz-seo-row__desc"><?=Loc::getMessage('AWZ_SEO_OPT_META_PAGEN_FROM_DESC')?></div>
                </div>
            </div>

            <?
            $metaTplRows = [
                ['PAGEN_TITLE_TEMPLATE', 'AWZ_SEO_OPT_META_PAGEN_TITLE', 'AWZ_SEO_OPT_META_PAGEN_TITLE_DESC', $valPagenTitle, '– страница #PAGE#'],
                ['PAGEN_DESCRIPTION_TEMPLATE', 'AWZ_SEO_OPT_META_PAGEN_DESCRIPTION', 'AWZ_SEO_OPT_META_PAGEN_DESCRIPTION_DESC', $valPagenDesc, '(страница #PAGE#)'],
                ['PAGEN_PAGE_TITLE_TEMPLATE', 'AWZ_SEO_OPT_META_PAGEN_PAGE_TITLE', 'AWZ_SEO_OPT_META_PAGEN_PAGE_TITLE_DESC', $valPagenPageTitle, '– страница #PAGE#'],
            ];
            foreach($metaTplRows as $row){
                ?>
                <div class="awz-seo-row">
                    <div class="awz-seo-row__label"><?=Loc::getMessage($row[1])?></div>
                    <div class="awz-seo-row__ctrl">
                        <input class="awz-seo-input awz-seo-input--w" type="text" value="<?=htmlspecialcharsbx($row[3])?>" placeholder="<?=htmlspecialcharsbx($row[4])?>" name="META[<?=$row[0]?>]"<?=$disabledAttr?>>
                        <div class="awz-seo-row__desc"><?=Loc::getMessage($row[2])?></div>
                    </div>
                </div>
                <?
            }
            ?>

            <div class="awz-seo-row">
                <div class="awz-seo-row__label"><?=Loc::getMessage('AWZ_SEO_OPT_META_ROBOTS_DEFAULT')?></div>
                <div class="awz-seo-row__ctrl">
                    <label class="awz-switch">
                        <input type="checkbox" value="Y" name="META[ROBOTS_DEFAULT]" <?if($valRobotsDefault=="Y") echo "checked";?><?=$disabledAttr?>>
                        <span class="awz-switch__slider"></span>
                    </label>
                    <div class="awz-seo-row__desc"><?=Loc::getMessage('AWZ_SEO_OPT_META_ROBOTS_DEFAULT_DESC')?></div>
                </div>
            </div>

            <div class="awz-seo-row">
                <div class="awz-seo-row__label"><?=Loc::getMessage('AWZ_SEO_OPT_META_ROBOTS_NOINDEX')?></div>
                <div class="awz-seo-row__ctrl">
                    <label class="awz-switch">
                        <input type="checkbox" value="Y" name="META[ROBOTS_NOINDEX]" <?if($valRobotsNoindex=="Y") echo "checked";?><?=$disabledAttr?>>
                        <span class="awz-switch__slider"></span>
                    </label>
                    <div class="awz-seo-row__desc"><?=Loc::getMessage('AWZ_SEO_OPT_META_ROBOTS_NOINDEX_DESC')?></div>
                </div>
            </div>
        </div>

        <div class="awz-seo-footer">
            <button type="submit" name="Update" value="Y" class="awz-seo-btn awz-seo-btn--primary"<?=$disabledAttr?>>
                <?=Loc::getMessage('AWZ_SEO_OPT_L_BTN_SAVE')?>
            </button>
            <?if(AccessController::isViewRight()){?>
                <button type="button" class="awz-seo-btn awz-seo-btn--ghost" onclick="BX.SidePanel.Instance.open('<?=$saveUrl?>');return false;">
                    <?=Loc::getMessage('AWZ_SEO_OPT_SECT2')?>
                </button>
            <?}?>
        </div>
    </form>

    <div class="awz-seo-check">
        <div>
            <div style="font-weight:600;font-size:14px;color:#1d2433;"><?=Loc::getMessage('AWZ_SEO_OPT_CHECK_HEAD')?></div>
            <div style="font-size:12px;color:#5b6472;margin-top:3px;"><?=Loc::getMessage('AWZ_SEO_OPT_CHECK_DESC')?></div>
        </div>
        <form id="awz_seo_check_form" method="POST" action="<?=$checkServiceUrl?>" target="_blank" style="margin-left:auto;">
            <input type="hidden" name="payload" value="<?=htmlspecialcharsbx($checkPayload)?>">
            <a href="#" class="awz-seo-btn awz-seo-btn--primary" onclick="document.getElementById('awz_seo_check_form').submit();return false;">
                <?=Loc::getMessage('AWZ_SEO_OPT_CHECK_LINK')?>
            </a>
        </form>
    </div>
</div>
<?
if($request->get('saved')==='Y'){
    Extension::load("ui.notification");
    ?>
    <script>BX.ready(function(){BX.UI.Notification.Center.notify({options: {text: "<?=AddSlashesJs(Loc::getMessage('AWZ_SEO_OPT_SAVED'))?>", closeOnCorrespondingEvent: true}});});</script>
    <?
}
if($request->get('csv_saved')==='Y'){
    Extension::load("ui.notification");
    $csvCount = (int)$request->get('csv_count');
    $csvMsg = $csvCount > 0
        ? sprintf(Loc::getMessage('AWZ_SEO_OPT_CSV_SAVED'), $csvCount)
        : Loc::getMessage('AWZ_SEO_OPT_CSV_SAVED_EMPTY');
    ?>
    <script>BX.ready(function(){BX.UI.Notification.Center.notify({options: {text: "<?=AddSlashesJs($csvMsg)?>", closeOnCorrespondingEvent: true}});});</script>
    <?
}
require($_SERVER["DOCUMENT_ROOT"]."/bitrix/modules/main/include/epilog_admin.php");
