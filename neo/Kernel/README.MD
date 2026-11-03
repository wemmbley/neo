# Neo Kernel

**Kernel** — ядро приложения Neo, отвечающее за управление жизненным циклом приложения, регистрацию провайдеров, разрешение зависимостей и вызов объектов.

## 1. Архитектура

Kernel разделяет ответственность между несколькими компонентами:

```text
Neo/
└── Kernel/
    ├── Application.php
    ├── ServiceContainer.php
    ├── ServiceContainerException.php
    ├── DependencyResolverInterface.php
    ├── DefaultDependencyResolver.php
    └── ProfilerDependencyResolver.php
```

### Назначение компонентов

| Компонент | Ответственность |
|---|---|
| `Application` | Управление жизненным циклом приложения |
| `ServiceContainer` | Хранение регистраций и экземпляров зависимостей |
| `ServiceContainerException` | Исключения контейнера |
| `DependencyResolverInterface` | Контракт механизма разрешения зависимостей |
| `DefaultDependencyResolver` | Стандартное разрешение зависимостей |
| `ProfilerDependencyResolver` | Профилирование операций разрешения и вызова |

Основной принцип архитектуры — разделение хранения зависимостей и механизма их разрешения.

`ServiceContainer` управляет состоянием контейнера, а `DependencyResolverInterface` определяет способ получения объектов и вызова методов.

---

## 2. Application

`Application` представляет собой центральный объект приложения.

Он управляет провайдерами, предоставляет доступ к контейнеру и выступает точкой входа для получения зависимостей.

### Создание приложения

```php
use Neo\Kernel\Application;
use Neo\Kernel\ServiceContainer;

$app = new Application(
    container: new ServiceContainer(),
    providers: $this->autoloadProviders,
);

$app->run();
```

### Жизненный цикл

Приложение использует двухэтапную загрузку провайдеров:

1. `register()` — регистрация сервисов и конфигураций.
2. `boot()` — запуск и инициализация зарегистрированных сервисов.

Сначала вызывается `register()` у всех провайдеров, затем `boot()` у всех провайдеров.

```php
public function boot(): void
{
    foreach ($this->providers as $provider) {
        $provider->register();
    }

    foreach ($this->providers as $provider) {
        $provider->boot();
    }
}
```

Такой порядок позволяет провайдерам зарегистрировать необходимые зависимости до начала их инициализации.

### API Application

| Метод | Назначение |
|---|---|
| `run()` | Запускает жизненный цикл приложения |
| `boot()` | Регистрирует и загружает провайдеры |
| `getInstance()` | Возвращает текущий экземпляр Application |
| `make()` | Получает зависимость через контейнер |
| `getContainer()` | Возвращает ServiceContainer |
| `registerProvider()` | Добавляет провайдер |
| `getProviders()` | Возвращает список провайдеров |

### Получение приложения

```php
$app = Application::getInstance();
```

Если приложение ещё не создано, `getInstance()` выбрасывает `LogicException`.

### Получение зависимости через Application

```php
$service = Application::getInstance()
    ->make(MyService::class);
```

---

## 3. ServiceContainer

`ServiceContainer` — контейнер зависимостей (Dependency Injection Container).

Он отвечает за:

- регистрацию зависимостей;
- хранение готовых экземпляров;
- управление Singleton;
- получение объектов;
- делегирование разрешения зависимостей резолверу;
- вызов функций и методов через механизм разрешения параметров.

### Создание

```php
use Neo\Kernel\ServiceContainer;

$container = new ServiceContainer();
```

Контейнер принимает необязательный резолвер:

```php
$container = new ServiceContainer(
    resolver: new DefaultDependencyResolver(),
);
```

Если резолвер не передан, используется `DefaultDependencyResolver`.

### Регистрация зависимости

#### bind()

Регистрирует зависимость без гарантии повторного использования созданного экземпляра.

```php
$container->bind(
    MyService::class,
    MyService::class,
);
```

Можно передать Closure:

```php
$container->bind(
    MyService::class,
    fn ($container, $parameters) => new MyService(),
);
```

#### singleton()

Регистрирует зависимость как Singleton.

Созданный экземпляр сохраняется в контейнере и возвращается при последующих обращениях.

```php
$container->singleton(
    MyService::class,
    MyService::class,
);
```

#### instance()

Регистрирует уже созданный экземпляр.

```php
$service = new MyService();

$container->instance(
    MyService::class,
    $service,
);
```

### Получение зависимости

#### make()

Основной метод получения объекта.

```php
$service = $container->make(MyService::class);
```

Можно передавать дополнительные параметры:

```php
$service = $container->make(
    MyService::class,
    ['name' => 'Neo'],
);
```

#### get()

Альтернативный интерфейс получения зависимости.

```php
$service = $container->get(MyService::class);
```

### Проверка и управление регистрациями

| Метод | Назначение |
|---|---|
| `has()` | Проверяет наличие зависимости |
| `forget()` | Удаляет регистрацию или экземпляр согласно реализации метода |
| `bind()` | Регистрирует обычную зависимость |
| `singleton()` | Регистрирует Singleton |
| `instance()` | Регистрирует готовый экземпляр |

### Вызов методов и функций

Контейнер предоставляет `call()` для вызова callback с автоматическим разрешением параметров.

```php
$result = $container->call(
    [MyService::class, 'handle'],
);
```

Поддерживаемые варианты callback:

```php
// Closure
$container->call(
    fn (MyService $service) => $service->handle(),
);

// Функция
$container->call('some_function');

// Метод объекта
$container->call([$service, 'handle']);

// Метод класса
$container->call([MyService::class, 'handle']);

// Строковая запись Class@method
$container->call('App\Services\MyService@handle');

// Invokable-объект
$container->call(new MyInvokable());
```

Фактическая поддержка конкретной формы определяется реализацией `call()`.

### Управление резолвером

Контейнер позволяет заменить механизм разрешения зависимостей.

```php
$container->setResolver(
    new DefaultDependencyResolver(),
);
```

Получить текущий резолвер:

```php
$resolver = $container->getResolver();
```

Это позволяет подключать альтернативные реализации без изменения основного API контейнера.

---

## 4. DependencyResolverInterface

Контракт механизма разрешения зависимостей.

Он отделяет алгоритм создания объектов от самого контейнера.

```php
interface DependencyResolverInterface
{
    public function resolve(
        ServiceContainer $container,
        string $abstract,
        array $parameters = [],
    ): mixed;

    public function call(
        ServiceContainer $container,
        callable|array|string $callback,
        array $parameters = [],
    ): mixed;
}
```

### resolve()

Получает зависимость по её идентификатору.

Аргументы:

| Аргумент | Назначение |
|---|---|
| `$container` | Контейнер, предоставляющий регистрации и экземпляры |
| `$abstract` | Идентификатор зависимости |
| `$parameters` | Дополнительные параметры создания |

### call()

Вызывает callback с автоматическим разрешением его параметров.

Такой контракт позволяет использовать один механизм разрешения параметров как для создания объектов, так и для вызова методов.

---

## 5. DefaultDependencyResolver

`DefaultDependencyResolver` — стандартная реализация `DependencyResolverInterface`.

Именно здесь находится основная логика создания объектов.

### Разрешение зависимости

Общий алгоритм:

1. Проверка наличия готового экземпляра.
2. Проверка циклической зависимости.
3. Получение регистрации из контейнера.
4. Начало процесса разрешения.
5. Определение конкретной реализации.
6. Создание объекта или вызов Closure.
7. Сохранение экземпляра, если зависимость зарегистрирована как Singleton.
8. Завершение процесса разрешения.

### Создание объектов

Если конкретная реализация совпадает с абстракцией, резолвер использует Reflection для создания объекта.

Если регистрация указывает на другую реализацию, резолвер делегирует её создание контейнеру.

```php
$container->bind(
    LoggerInterface::class,
    FileLogger::class,
);
```

При запросе:

```php
$logger = $container->make(LoggerInterface::class);
```

Контейнер получает `FileLogger`.

### Автоматическое разрешение параметров

Резолвер анализирует конструктор через Reflection.

При разрешении параметров используются следующие источники:

1. Переданные параметры по имени.
2. Переданные объекты по имени класса.
3. Зарегистрированные зависимости контейнера.
4. Значения параметров по умолчанию.
5. `null`, если параметр допускает отсутствие значения.
6. Variadic-параметры обрабатываются отдельно.

Если обязательную зависимость разрешить невозможно, выбрасывается `ServiceContainerException`.

### Пример Constructor Injection

```php
class TestService
{
    public function handle(): void
    {
        // ...
    }
}

class MyService
{
    public function __construct(
        private TestService $service,
    ) {}

    public function handle(): void
    {
        $this->service->handle();
    }
}
```

Теперь достаточно запросить:

```php
$service = $container->make(MyService::class);

$service->handle();
```

`TestService` будет автоматически создан и передан в конструктор `MyService`.

### Преобразование параметров

Резолвер содержит механизм `coerce()` для преобразования строковых значений в числовые типы, когда это требуется типом параметра.

В частности, предусмотрено преобразование строк в `int` и `float`.

### Обнаружение циклических зависимостей

Контейнер ведёт список разрешаемых зависимостей.

Например:

```text
ServiceA
  └── ServiceB
        └── ServiceA
```

При повторном обращении к уже разрешаемой зависимости возникает `ServiceContainerException`.

Это предотвращает бесконечную рекурсию при создании объектов.

---

## 6. ProfilerDependencyResolver

`ProfilerDependencyResolver` — декоратор, реализующий `DependencyResolverInterface`.

Он оборачивает другой резолвер и собирает статистику его работы.

Основная задача — измерять производительность разрешения зависимостей и вызовов.

### Подключение

```php
use Neo\Kernel\ProfilerDependencyResolver;
use Neo\Kernel\DefaultDependencyResolver;
use Neo\Kernel\ServiceContainer;

$resolver = new ProfilerDependencyResolver(
    new DefaultDependencyResolver(),
);

$container = new ServiceContainer(
    resolver: $resolver,
);
```

Также можно заменить резолвер уже созданного контейнера:

```php
$container->setResolver(
    new ProfilerDependencyResolver(
        $container->getResolver(),
    ),
);
```

### Собираемая статистика

| Метрика | Назначение |
|---|---|
| `total_resolutions` | Общее количество операций разрешения |
| `total_calls` | Общее количество операций вызова |
| `successful_resolutions` | Успешные разрешения |
| `failed_resolutions` | Неудачные разрешения |
| `total_time` | Суммарное время разрешения |
| `average_time` | Среднее время разрешения |
| `max_time` | Максимальное время одной операции |
| `total_memory_delta` | Суммарное изменение использования памяти |
| `peak_memory` | Пиковое использование памяти |
| `max_depth` | Максимальная глубина вложенности |
| `classes` | Статистика по отдельным классам |

Время измеряется с использованием `hrtime()`.

### Статистика по классам

Для каждого класса собираются:

| Метрика | Назначение |
|---|---|
| `count` | Количество разрешений |
| `success` | Количество успешных операций |
| `failed` | Количество неудачных операций |
| `total_time` | Суммарное время |
| `max_time` | Максимальное время |

### Получение статистики

```php
$container = app()->getContainer();

$resolver = $container->getResolver();

dd($resolver->getStatistics());
```

### Пример результата

```php
[
    'total_resolutions' => 2,
    'total_calls' => 0,
    'successful_resolutions' => 2,
    'failed_resolutions' => 0,

    'total_time' => 0.5149,
    'average_time' => 0.25745,
    'max_time' => 0.5088,

    'total_memory_delta' => 0,
    'peak_memory' => 2097152,
    'max_depth' => 2,

    'classes' => [
        'App\Services\TestService' => [
            'count' => 1,
            'success' => 1,
            'failed' => 0,
            'total_time' => 0.0061,
            'max_time' => 0.0061,
        ],

        'App\Services\MyService' => [
            'count' => 1,
            'success' => 1,
            'failed' => 0,
            'total_time' => 0.5088,
            'max_time' => 0.5088,
        ],
    ],
]
```

### Сброс статистики

```php
$resolver->reset();
```

Метод сбрасывает накопленные показатели профилировщика.

### Особенности профилирования

`ProfilerDependencyResolver` измеряет операции, которые проходят через него.

Если контейнер возвращает готовый экземпляр до обращения к резолверу, такое попадание в кеш не учитывается как отдельная операция разрешения.

`peak_memory` отражает пиковое использование памяти PHP, а `total_memory_delta` — измеряемое изменение использования памяти между началом и завершением операции.

---

## 7. Исключения

`ServiceContainerException` используется для ошибок, связанных с разрешением зависимостей.

К типичным ситуациям относятся:

- циклическая зависимость;
- невозможность разрешить обязательный параметр;
- невозможность создать объект;
- некорректная регистрация зависимости.

Пример:

```php
try {
    $service = $container->make(MyService::class);
} catch (ServiceContainerException $exception) {
    echo $exception->getMessage();
}
```

---

## 8. Практический пример

Рассмотрим приложение с двумя сервисами.

### TestService

```php
namespace App\Services;

class TestService
{
    public function handle(): void
    {
        echo 'TestService handled';
    }
}
```

### MyService

```php
namespace App\Services;

class MyService
{
    public function __construct(
        private TestService $service,
    ) {}

    public function handle(): void
    {
        $this->service->handle();
    }
}
```

### Bootstrap

После запуска приложения контейнер доступен через глобальный helper `app()`.

```php
<?php

require_once dirname(__DIR__) . '/bootstrap/Bootstrap.php';

use App\Services\MyService;
use Bootstrap\Bootstrap;

$bootstrap = new Bootstrap();

$bootstrap->boot();

app(MyService::class)->handle();
```

### Получение статистики

```php
$container = app()->getContainer();

$resolver = $container->getResolver();

dd($resolver->getStatistics());
```

В результате профилировщик покажет разрешение `MyService` и его вложенной зависимости `TestService`.

---

## 9. Архитектурные принципы Kernel

### Разделение ответственности

`ServiceContainer` отвечает за состояние и регистрацию зависимостей.

`DependencyResolverInterface` определяет контракт разрешения.

`DefaultDependencyResolver` реализует алгоритм разрешения.

`ProfilerDependencyResolver` добавляет измерение производительности, не заменяя основную реализацию.

### Расширяемость

Новый механизм разрешения можно реализовать через `DependencyResolverInterface`.

Например, в будущем могут появиться:

- `CachedDependencyResolver` — дополнительное кеширование результатов разрешения;
- `DebugDependencyResolver` — расширенная диагностика;
- `TracingDependencyResolver` — трассировка графа зависимостей;
- `LazyDependencyResolver` — отложенное разрешение зависимостей.

Это возможные направления расширения, а не существующие компоненты Kernel.

### Декоратор вместо изменения контейнера

Профилирование реализовано через декоратор.

Это позволяет добавлять измерения к существующему механизму разрешения, сохраняя основной контракт и не перегружая `ServiceContainer` диагностической логикой.

---

## 10. Краткая карта API

| Класс | Метод | Назначение |
|---|---|---|
| `Application` | `run()` | Запуск приложения |
| `Application` | `boot()` | Загрузка провайдеров |
| `Application` | `make()` | Получение зависимости |
| `Application` | `getInstance()` | Текущий Application |
| `Application` | `getContainer()` | Получение контейнера |
| `Application` | `registerProvider()` | Регистрация провайдера |
| `ServiceContainer` | `bind()` | Регистрация зависимости |
| `ServiceContainer` | `singleton()` | Регистрация Singleton |
| `ServiceContainer` | `instance()` | Регистрация экземпляра |
| `ServiceContainer` | `make()` | Разрешение зависимости |
| `ServiceContainer` | `get()` | Получение зависимости |
| `ServiceContainer` | `call()` | Вызов callback |
| `ServiceContainer` | `has()` | Проверка наличия |
| `ServiceContainer` | `forget()` | Удаление регистрации |
| `ServiceContainer` | `setResolver()` | Установка резолвера |
| `ServiceContainer` | `getResolver()` | Получение резолвера |
| `DefaultDependencyResolver` | `resolve()` | Создание зависимости |
| `DefaultDependencyResolver` | `call()` | Вызов callback |
| `ProfilerDependencyResolver` | `getStatistics()` | Получение статистики |
| `ProfilerDependencyResolver` | `reset()` | Сброс статистики |

---

**Итог:** Kernel формирует инфраструктурный слой Neo. Он управляет приложением и провайдерами, предоставляет контейнер зависимостей и отделяет алгоритм разрешения объектов от механизма их хранения. Благодаря контракту резолвера архитектура допускает замену и декорирование механизма Dependency Injection без изменения пользовательского API контейнера.