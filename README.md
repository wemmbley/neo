# Neo PHP Framework

Ядро приложения для PHP. PHP 8.5+

## Foundation
- Автозагрузчик классов и файлов.
- Конфигурации в массиве и хелпер `config()`/`env()`.
- ServiceProvider, ServiceContainer, Facade.
- Консоль приложения и отдельные команды `php cli`.
- Профилировщик с замерами для контейнера `profiler()`.

## Провайдеры

#### Пример провайдера

```php
class FooProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->loadConfig(__DIR__ . '/config.php', 'foo'); // конфиг приложения сильнее
        $this->app->singleton(FooContract::class, Foo::class);
        $this->app->bind(FooContract::class, Foo::class);
        $this->commands([FooCommand::class]);
    }
}
```

## Профилировщик

`APP_PROFILER=true` в `.env`, затем `profiler()` в нужном месте.
