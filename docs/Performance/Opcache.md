# Opcache

## Конфиг

```php 
return [
    'enabled' => env('OPCACHE_ENABLED', true),
    
    'memory_consumption' => env('OPCACHE_CACHE_MEMORY', 512),
    
    'interned_strings_buffer' => env('OPCACHE_CACHE_STRINGS', 64),
    
    'max_accelerated_files' => env('OPCACHE_MAX_FILES', 32531),
    
    'validate_timestamps' => env('OPCACHE_OPTIMIZED', false),
    
    'save_comments' => false,
    
    'enable_cli' => true,
    
    'directories' => [
        base_path('app'),
        base_path('neo'),
        base_path('src'),
    ],
    
    'exclude' => [
        'Tests',
        'Test',
        'Stubs',
        'Stub',
    ],
];
```

## Команды

```bash 
php cli opcache:status
```

```bash 
php cli opcache:clear
```

```bash 
php cli opcache:precompile
```