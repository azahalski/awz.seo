# AWZ: SEO

<!-- dev-start -->

## Документация для разработчиков

### События модуля

| Событие | Обработчик | Описание |
|---|---|---|
| `main::OnPageStart` | `\Awz\Seo\Redirect\Handler::OnPageStart` | Обработка редиректов |
| `main::OnEpilog` | `\Awz\Seo\Canonical\Handler::OnEpilog` | Вывод canonical-тега |
| `main::OnEpilog` | `\Awz\Seo\Meta\Handler::OnEpilog` | Мета-теги и пагинация |
| `main::OnAfterUserUpdate` | `\Awz\Seo\Access\Handlers::OnAfterUserUpdate` | Синхронизация прав доступа |

### Проверка прав доступа

```php
if(\Bitrix\Main\Loader::includeModule('awz.seo')){
    $allow = \Awz\Seo\Access\AccessController::can(
        $userId,
        \Awz\Seo\Access\Custom\ActionDictionary::ACTION_MODULE_SETT_EDIT
    );
}
```

Доступные действия: `ACTION_MODULE_SETT_VIEW/EDIT`, `ACTION_MODULE_RIGHT_VIEW/EDIT`, `ACTION_GENS_PAGE`, `ACTION_GENS_RIGHT`.

<!-- dev-end -->

<!-- cl-start -->

## История версий

https://github.com/azahalski/awz.seo/blob/main/CHANGELOG.md

<!-- cl-end -->

---

**Автор модуля**: Andrew Zahalski  
**Документация**: [https://zahalski.dev/modules/awz.seo/](https://zahalski.dev/modules/awz.seo/)
