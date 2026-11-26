# Test D: php-фенс в dev

<!-- dev-start -->

## Код D

```php
if(\Bitrix\Main\Loader::includeModule('awz.seo')){
    $allow = \Awz\Seo\Access\AccessController::can($userId);
}
```

<!-- dev-end -->

Хвост файла.
