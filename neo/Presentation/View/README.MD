# Шаблонизатор

## Пример инициализации

```php
$config = [

    # Базовая папка с шаблонами без пространства имён.
    'basic_path' => __DIR__ . '/folder/views',
     
    # Пространство имён для разделения папок.
    'namespaces' => [
        'mynamespace' => __DIR__ . '/folder/views',
    ],
    
    # Полная настройка кэширования.
    'cache' => [
    
        # Включает кэш по умолчанию. Можно подставить сразу env().
        'enabled' => true,
        
        # Эта опция работает на ВСЕ шаблоны без исключения. 
        # С ней директива @cache становится бесмысленной.
        'strategy' => 'full', # full|directive
    
        # Директория для кэширования шаблонов. Ускоряет загрузку.
        'path' => __DIR__ . '/folder/views_cache',
        
        # Указывает время жизни кэша до обновления. 
        # 0 - бессрочно до инвалидации.
        'lifetime' => 3600*12,
        
    ],
    
    # Включение или отключение отладочного режима.
    # Повышает нагрузку на память и выводит много информации. 
    # На бизнес-сервере лучше отключить.
    'debug' => true,
    
    # Устанавливает конкретное расширение поиска шаблонов.
    # Приоритет поиска отдаётся первому расширению в списке.
    'extensions' => ['.blade.php'],
    
    # Базовая настройка кодировки. Менять нет смысла.
    'encoding' => 'UTF-8',
    
    # Livewire-подобные компоненты.
    # Сделано на будущее. Сейчас не работает.
    'components' => [
        # 'alert' => AlertComponent::class,
        # 'modal' => ModalComponent::class,
    ],
     
    # Установка настроек компилятора
    'compiler' => [
     
        # Базовые разделители для PHP переменных и выражений.
        'brackets' => ['{{', '}}'],
         
        # Директивы, регистрируемые при инициализации компилятора.
        'directives' => [
            \Neo\View\Directives\IfDirective::class,
            \Neo\View\Directives\ForeachDirective::class,
            \Neo\View\Directives\ForDirective::class,
            \Neo\View\Directives\WhileDirective::class,
            \Neo\View\Directives\JsonDirective::class,
            \Neo\View\Directives\SwitchDirective::class,
            \Neo\View\Directives\RawPhpDirective::class,
            \Neo\View\Directives\IncludeDirective::class,
            \Neo\View\Directives\VerbatimDirective::class,
            \Neo\View\Directives\PushDirective::class,
            \Neo\View\Directives\StackDirective::class,
            \Neo\View\Directives\SlotDirective::class,
            \Neo\View\Directives\SessionDirective::class,
            \Neo\View\Directives\OnceDirective::class,
            \Neo\View\Directives\FragmentDirective::class,
            \Neo\View\Directives\CsrfDirective::class,
            \Neo\View\Directives\ErrorDirective::class,
            \Neo\View\Directives\MethodDirective::class,
            \Neo\View\Directives\CacheDirective::class,
        ],
        
        # Здесь происходит внедрение зависимостей в директивы, 
        # Которые в конструкторе требуют реализации контракта.
        # Аргументы конструктора передаются по порядку.
        'dependency_injection' => [
            CsrfDirective::class => [
                CsrfContract::class => MyConcreteCsrfTokenClass::class,
            ],
            SessionDirective::class => [
                SessionContract::class => MySessionHandler::class,
            ],
        ],
        
    ],
];

$view = new View($config);
```

## Пример использования
```php 
$view->render('mynamespace::home', ['param' => 'value']);
```

## Расширение функционала

Унаследовавшись от `BasicDirective` мы можем расширить наш
компилятор любыми директивами. В данном случае мы создаём 
директиву `@if`, `@endif`. 

```php 
class IfDirective extends BasicDirective
{
    public string $directive = '@if';
    public ?string $directiveEnd = '@endif';
    
    public function render(string $expression): string
    {
        return "<?php if ({$expression}): ?>";
    }

    public function close(): ?string
    {
        return "<?php endif; ?>";
    }
}
```

Пример без закрывающей директивы:
```php 
class JsonDirective extends BasicDirective
{
    public string $directive = '@json';

    public function render(string $expression): string
    {
        return "<?php echo json_encode({$expression}); ?>";
    }
}
```

Подключение Вашего класса происходит в момент бутстрапа приложения 
либо через `ServiceProvider` если Вы используете модуль который 
не имеет доступа к управлению этой точкой входа в приложение:

```php 
class AppServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Blade::directive(IfDirective::class);
        Blade::directive(JsonDirective::class);
    }
}
```

Оно подключит Ваши директивы "на лету" перед загрузкой всего 
приложения, соответственно эти директивы успеют отработать 
в момент рендеринга любого Blade шаблона.