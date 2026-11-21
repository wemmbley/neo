# Foundation: фундамент

В современном PHP тяжело обойтись без базовых вещей: автозагрузки, конфигурации,
переменных окружения, консоли и команд, внедрения зависимостей (Dependency Inversion),
профилировщика производительности, тестирования и дампинга.

Такой набор позволяет делать стабильные (тестирование), расширяемые (внедрение
зависимостей) и быстрые (профилировщик) приложения. Foundation работает без Composer и
выступает опорной точкой для всех будущих пакетов.

Требования: PHP 8.5+.

## Что входит

| Модуль          | Назначение                                                                  |
|-----------------|-----------------------------------------------------------------------------|
| `Autoload`      | PSR-4 автозагрузчик на `spl_autoload_register`, `AliasLoader` для фасадов   |
| `Config`        | `Config`, `Env`, `DotNotation`, хелперы `env()` и `config()`                |
| `Console`       | `CommandProcessor`, `Command`, `Input`, `Output`, `HelpCommand`             |
| `Kernel`        | `Kernel`, `ServiceContainer`, резолверы, провайдеры, фасады, бутстрапперы   |

## Структура проекта

```
cli                          консольная точка входа
public/index.php             web-точка входа
.env                         переменные окружения
app/Bootstrap/Bootstrap.php  автозагрузка + создание Kernel (run / console)
app/Config/*.php             настройки, каждый файл возвращает массив
app/Commands, app/Providers  код приложения (namespace App\)
neo/Foundation/              этот пакет (namespace Neo\Foundation\)
```

## Развертывание

### 1. `app/Bootstrap/Bootstrap.php`

```php
<?php

declare(strict_types=1);

namespace App\Bootstrap;

use Neo\Foundation\Autoload\AliasLoader;
use Neo\Foundation\Autoload\Autoload;
use Neo\Foundation\Config\Config;
use Neo\Foundation\Console\CommandProcessor;
use Neo\Foundation\Kernel\Kernel;
use Neo\Foundation\Kernel\ServiceContainer;

class Bootstrap
{
    private ?Kernel $kernel = null;

    public function __construct(private readonly array $config) {}

    public function run(): void
    {
        $this->kernel()->run();
    }

    public function console(): int
    {
        try {
            return $this->kernel()->make(CommandProcessor::class)->run();
        } catch (\Throwable $e) {
            fwrite(STDERR, $e::class . ': ' . $e->getMessage() . PHP_EOL);

            return 1;
        }
    }

    public function kernel(): Kernel
    {
        if ($this->kernel !== null) {
            return $this->kernel;
        }

        $this->registerAutoload();

        $kernel = new Kernel(
            new ServiceContainer(),
            $this->config['base_path'],
            envFile: $this->config['env'] ?? null,
        );

        $kernel->bootstrap();

        /** @var Config $config */
        $config = $kernel->make(Config::class);

        (new AliasLoader())
            ->facades((array) $config->get('app.facades', []))
            ->register();

        return $this->kernel = $kernel;
    }

    private function registerAutoload(): void
    {
        # Сам загрузчик подключаем руками: автозагрузки ещё нет.
        require_once $this->config['autoload']['loader'];

        (new Autoload(
            $this->config['autoload']['classes'],
            $this->config['autoload']['files'] ?? [],
        ))->load();
    }
}
```

### 2. `app/Config/Bootstrap.php` (конфиг запуска)

Единственный файл, который знает пути. Всё остальное ядро получает отсюда.

```php
<?php

declare(strict_types=1);

$base = dirname(__DIR__, 2);

return [
    # Корень проекта: от него считаются basePath() и configPath().
    'base_path' => $base,

    # Файл .env. Нет ключа или null: .env не загружается.
    'env' => $base . '/.env',
    
    # Веосия PHP и обязательные расширения.
    'requires' => [
        'php >= 8.5',
        'php_ext' => [
            'curl',
            'pdo_mysql',
            'pdo_sqlite',
            'sockets',
            'zip',
        ],
    ],

    'autoload' => [
        # Загрузчик подключается вручную, поэтому путь к нему задан отдельно.
        'loader' => $base . '/neo/Foundation/Autoload/Autoload.php',
        
        # Путь к файлу проверяющему версию PHP и его пакеты.
        'requirements' => $base . '/neo/Foundation/Kernel/Requirements.php',

        # Путь => пространство имён.
        'classes' => [
            $base . '/neo' => 'Neo',
            $base . '/app' => 'App',
        ],

        # Хелперы. Несуществующий файл это исключение, а не тихий пропуск.
        'files' => [
            $base . '/neo/Foundation/Kernel/helpers.php',
            $base . '/neo/Foundation/Config/helpers.php',
            $base . '/neo/Foundation/Debug/helpers.php',
            $base . '/neo/Foundation/Testing/helpers.php',
        ],
    ],
];
```

Этот файл лежит в `app/Config`, поэтому он тоже читается как настройка:
`config('bootstrap.base_path')`.

### 3. Настройки приложения

Каждый `*.php` из `app/Config` должен вернуть массив. Имя файла становится первым
сегментом ключа: `app.php` даёт `config('app.name')`. Регистр имени файла не важен
(`App.php` и `app.php` дают ключ `app`, см. раздел «Config»).

`app/Config/app.php`:

```php
return [
    'name'      => env('APP_NAME', 'Neo'),
    'env'       => env('APP_ENV', 'production'),
    'debug'     => env('APP_DEBUG', false),
    'profiler'  => env('APP_PROFILER', false),
    'providers' => [],   # провайдеры, регистрируемые после бутстрапперов
    'facades'   => [],   # фасады, которым выдаётся короткий алиас
];
```

`app/Config/console.php`:

```php
return [
    # Команды приложения (встроенные уже подключены).
    'commands' => [],

    # Папки с тестами для `php cli test`.
    'tests' => [
        base_path('app/Tests'),
    ],
];
```

### 4. `public/index.php`

```php
<?php

declare(strict_types=1);

use App\Bootstrap\Bootstrap;

# Корень: dirname выходит на папку выше public.
$root = dirname(__DIR__);

# Базовый конфиг запуска.
$config = require $root . '/app/Config/Bootstrap.php';

# Первый класс подключаем руками, дальше работает автозагрузка.
require_once $root . '/app/Bootstrap/Bootstrap.php';

(new Bootstrap($config))->run();
```

### 5. `cli`

```php
<?php

declare(strict_types=1);

use App\Bootstrap\Bootstrap;

$root = __DIR__;

$config = require $root . '/app/Config/Bootstrap.php';

require_once $root . '/app/Bootstrap/Bootstrap.php';

exit((new Bootstrap($config))->console());
```

Приложение запущено, контейнер готов к использованию.

## Порядок запуска Kernel

`Kernel::bootstrap()` выполняет инициализацию в строго заданной последовательности.

### 1. Бутстрапперы

**EnvBootstrapper.** Читает файл из ключа `env` конфига запуска. Приоритет за настоящим
окружением сервера: значения из `.env` не перезаписывают уже существующие переменные.

**ConfigBootstrapper.** Читает все `*.php` из `app/Config` (`Kernel::configPath()`).
`env()` к этому моменту уже работает. Объект `Config` регистрируется в контейнере.
При `app.profiler = true` включается профилировщик.

**ConsoleBootstrapper.** Лениво привязывает `Output` и `CommandProcessor` (в вебе они не
создаются: константы `STDOUT` там нет). В процессор попадают встроенные команды,
команды из `console.commands` и команды провайдеров.

### 2. Регистрация провайдеров

После бутстрапперов регистрируются провайдеры из `app.providers`. Выполняется `register()`,
но не `boot()`.

### 3. Запуск

`boot()` провайдеров откладывается до фактического запуска.

- **Web:** `boot()` вызывается в `run()`.
- **Console:** `boot()` вызывается перед любой командой, у которой `disableMounting = false`.

Без полной загрузки выполняются команды: `help`, `config:show`, `env:set`, `test`.

Главный принцип: регистрация инфраструктуры отделена от полноценной инициализации.
Лёгкие системные операции не запускают всю экосистему приложения.

## Контейнер

```php
$container->bind(Foo::class, FooImpl::class);          # новый объект на каждый запрос
$container->singleton(Cache::class, FileCache::class); # один объект
$container->instance(Config::class, $config);          # готовый объект
$container->make(Mailer::class, ['retries' => 5]);     # автосборка по конструктору
$container->call(fn (Cache $cache) => $cache->get()); # внедрение в вызов
```

Автосборка находит зависимости по типам конструктора, ловит циклические зависимости
и сообщает понятную ошибку, если для интерфейса нет привязки.

Хелпер `app()` возвращает Kernel, `app(Foo::class)` собирает объект.

## Сервис-провайдеры

```php
class FooProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->loadConfig(__DIR__ . '/config.php', 'foo');
        $this->app->singleton(FooContract::class, Foo::class);
        $this->commands([FooCommand::class]);
    }

    public function boot(): void {}
}
```

`register()` только привязывает сервисы, `boot()` может пользоваться любыми.

Методы трейта `NeoServiceProviderTrait`:

- `loadConfig($path, $key)`: догружает конфиг пакета, значения приложения сильнее;
- `commands([...])`: регистрирует консольные команды (только в CLI);
- `loadRoutes`, `loadViews`, `loadTranslations`, `loadMigrations`, `publish`: запоминают
  заявки в `Kernel::resources('routes'|'views'|...)`, читать их будут соответствующие модули.

**Ленивые провайдеры.** Реализуйте `DeferrableProviderContract::provides()` и регистрируйте
класс строкой: провайдер создастся только при первом запросе одного из его сервисов.

## Config

```php
config('app.name');              # значение
config('app.name', 'default');   # со значением по умолчанию
config(['app.debug' => true]);   # запись
config();                        # объект Config
```

Имена файлов приводятся к нижнему регистру. Для этого в `Config::loadDirectory()`
должна быть строка:

```php
$items[strtolower(basename($file, '.php'))] = $value;
```

## Env

```
APP_DEBUG=true
db.password="секрет с пробелами"
APP_URL=https://${DOMAIN}/shop
```

- ключи не зависят от регистра, точка равна подчёркиванию (`env('db.host')` и `env('DB_HOST')`);
- `true`, `false`, `null`, `empty` превращаются в соответствующие значения;
- настоящие переменные окружения сервера сильнее файла;
- строка, которую нельзя разобрать, это исключение с номером строки.

`Env::raw()` отдаёт значение без превращения слов (для паролей), `Env::bool()` и `Env::int()`
приводят тип.

## Консоль

```
php cli                      # справка
php cli help <команда>
php cli config:show [ключ]
php cli env:set КЛЮЧ значение
php cli kernel:providers
php cli test [путь] [--filter=имя]
```

Команда это класс с `$signature` в синтаксисе Laravel. Зависимости внедряются в конструктор
(обязателен `parent::__construct()`).

```php
class HelloCommand extends Command
{
    protected string $signature = 'hello {name=мир} {--S|shout}';
    protected string $description = 'Здоровается';

    public function handle(): int
    {
        $this->info('Привет, ' . $this->argument('name'));

        return self::SUCCESS;
    }
}
```

Если команда не нуждается в загрузке провайдеров, поставьте `public bool $disableMounting = true;`.