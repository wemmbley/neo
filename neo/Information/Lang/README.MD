# Языковой процессор

```php 
$lang = new LangProcessor(string $locale, string $fallbackLocale);
$lang->addNamespace(string $namespace, string $path);
$lang->run();

echo __('namespace::file');
```