# Neo\Kernel

Ядро приложения: контейнер зависимостей, сервис-провайдеры и точка сборки `Application`.

| Класс | Роль |
|---|---|
| `Application` | Точка сборки: владеет контейнером и провайдерами, запускает жизненный цикл |
| `ServiceContainer` | Привязки, синглтоны, автосборка зависимостей, ленивые загрузчики |
| `ServiceProvider` | Место, где часть приложения настраивает себя |
| `DeferrableProvider` | Маркер ленивого провайдера |
| `DefaultDependencyResolver` | Обычный резолвер (рефлексия, автосборка) |
| `ProfilerDependencyResolver` | Обёртка над резолвером, собирает статистику |

## Быстрый старт

```php
$app = new Application(new ServiceContainer(), [
    CacheServiceProvider::class,   // имя класса
    new RouteServiceProvider($app), // или готовый объект
]);

$app->run(); // = boot()
```

Конструктор сам кладёт в контейнер `Application` и `ServiceContainer`, поэтому
провайдеры и любые сервисы могут запросить их через конструктор.
Текущее приложение: `Application::getInstance()`.

## Контейнер

```php
$app->bind(LoggerContract::class, FileLogger::class);      // каждый раз новый
$app->singleton(CacheContract::class, FileCache::class);   // один экземпляр
$app->singleton(Clock::class, fn (ServiceContainer $c) => new Clock());
$app->instance(Config::class, $config);                    // готовый объект

$app->make(CacheContract::class);
$app->make(Mailer::class, ['host' => 'smtp.local']);       // явные параметры
$container->call([$controller, 'index']);                  // вызов с автоподстановкой
$container->call('UserController@show', ['id' => '5']);
```

**Автосборка.** Аргументы конструктора подбираются в таком порядке:
значение из `$parameters` (по имени, затем по имени класса), класс из контейнера
или автозагрузки, значение по умолчанию, `null` для nullable. Иначе будет
`ServiceContainerException`. Строки `'5'` и `'1.5'` из `$parameters`
приводятся к `int`/`float`, если так объявлен аргумент.

**`call()`** принимает closure, `[объект|класс, 'метод']`, `'Класс@метод'`,
имя invokable-класса и invokable-объект.

**Ошибки** (`ServiceContainerException`): циклическая зависимость (в сообщении
вся цепочка), интерфейс без привязки, несуществующий или абстрактный класс,
аргумент, для которого не нашлось значения.

**Правила привязок:**
- `bind()` и `instance()` на тот же ключ заменяют старую привязку и сбрасывают созданный экземпляр;
- явная привязка важнее ленивой (см. ниже);
- `forget()` удаляет привязку, экземпляр и ленивый загрузчик.

## Провайдеры

```php
final class RouteServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(Router::class);   // только привязки
    }

    public function boot(): void
    {
        $this->app->make(Router::class)->loadRoutes(); // тут можно всё
    }
}
```

Провайдер обязан наследовать `ServiceProvider`. Через `$this->app` доступно всё
приложение.

### Жизненный цикл

1. `registerProvider()` создаёт провайдера и **сразу** вызывает `register()`.
   Привязки доступны ещё до `boot()`.
2. `boot()` вызывает `boot()` у каждого провайдера **один раз**. Повторный
   вызов `boot()` приложения ничего не делает.
3. Провайдер, добавленный после `boot()`, получает `register()` и `boot()` сразу.
   Провайдеры можно добавлять и из `register()`/`boot()` другого провайдера.

В `register()` не обращайтесь к чужим сервисам: их провайдеры могут ещё не
зарегистрироваться. Для этого есть `boot()`.

Одно и то же имя класса регистрируется один раз; объекты принимаются всегда.

### Ленивые провайдеры

Провайдер, реализующий `DeferrableProvider` и переданный **именем класса**,
не создаётся при старте. Он загрузится (`register()` + `boot()`) при первом
запросе любого сервиса из `provides()`.

```php
final class CacheServiceProvider extends ServiceProvider implements DeferrableProvider
{
    public static function provides(): array
    {
        return [CacheContract::class];
    }

    public function register(): void
    {
        $this->app->singleton(CacheContract::class, FileCache::class);
    }
}
```

- `provides()` статический: список читается без создания провайдера;
- `register()` должен привязать **все** перечисленные сервисы;
- если провайдер передан объектом, он загружается сразу (он уже создан);
- запрос ленивого сервиса из `has()` возвращает `true`, не создавая провайдера.

## Профилировщик

```php
$container->setResolver(
    new ProfilerDependencyResolver($container->getResolver())
);

// ... работа приложения ...

$profiler = $container->getProfiler(); // null, если профилировщик не включён
$profiler->getStatistics();  // сводка + classes[имя] по каждому сервису
$profiler->getTrace();       // дерево операций (родитель раньше детей, поле depth)
$profiler->getSlowest(10);   // самые дорогие сервисы по собственному времени
$profiler->reset();
```

- Время в миллисекундах, память в байтах.
- `time` включает вложенные зависимости, `self_time` только собственную работу.
  `total_time` складывается из `self_time`, поэтому вложенное не считается дважды.
- `getTrace()` хранит не более 500 операций.
- Профилируются только реальные сборки и вызовы `call()`. Обращения к уже
  созданным синглтонам контейнер отдаёт сам, до резолвера, и в статистику
  они не попадают.
- Профилировщик можно включать только в `debug`: на горячем пути он добавляет
  замеры времени и памяти к каждой сборке.

## Замена резолвера

Любой класс с интерфейсом `DependencyResolverContract` (`resolve()` и `call()`)
подключается через `$container->setResolver()` или вторым аргументом конструктора
`ServiceContainer`.