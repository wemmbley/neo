# Neo\Logger

Логирование движка: статический фасад `Log`, драйверы записи (файл, стек, «выключено»), каналы для отправки исключений (файл, Telegram) и валидируемая конфигурация.

> Пространства имён взяты из текущего кода. Если переносите классы, обновите этот файл.

## Быстрый старт

```php
$app->singleton(LogManager::class, fn () => new LogManager(new LoggerTransfer([
    'default' => 'daily',
    'drivers' => [
        'single' => ['driver' => 'single', 'path' => '/var/app/logs/app.log'],
        'daily'  => ['driver' => 'daily',  'path' => '/var/app/logs/app.log', 'level' => 'info'],
        'all'    => ['driver' => 'stack',  'drivers' => ['single', 'daily']],
        'off'    => ['driver' => 'disabled'],
    ],
    'channels' => [
        'file'     => ['channel' => 'file', 'driver' => 'daily'],
        'telegram' => ['channel' => 'telegram', 'token' => '...', 'chat_id' => '...'],
    ],
])));

Log::info('Пользователь {id} вошёл', ['id' => 5]);   // в драйвер по умолчанию
Log::driver('single')->warning('Только в single');
Log::stack(['single', 'daily'])->error('В два места');
Log::report($exception);                              // во все каналы
```

Строка в файле: `[2026-11-05 14:03:01] info: Пользователь 5 вошёл {"id":5}`

## Структура

| Класс | Namespace | Роль |
|---|---|---|
| `Log` | `Neo\Logger` | Статический фасад (`#[FacadeOf(LogManager::class)]`) |
| `LogManager` | `Neo\Logger\Managers` | Создаёт и кеширует драйверы и каналы. Сам реализует `ReportDriverContract` и делегирует вызовы драйверу по умолчанию |
| `LoggerTransfer` | `Neo\Logger\Transfers` | DTO конфигурации с валидацией (`default`, `drivers`, `channels`) |
| `BasicReportDriver` | `Neo\Logger\Drivers\Basic` | Уровни, фильтр по `level`, подстановка `{placeholders}` |
| `FileReportDriver` | `Neo\Logger\Drivers\Basic` | Базовый файловый драйвер: формат строки, каталог, `LOCK_EX`, нормализация контекста |
| `SingleReportDriver`, `DailyReportDriver`, `MonthlyReportDriver` | `Neo\Logger\Drivers` | Один файл, файл на день, файл на месяц |
| `StackReportDriver` | `Neo\Logger\Drivers` | Рассылает запись в несколько драйверов |
| `DisableReportingReportDriver` | `Neo\Logger\Drivers` | Ничего не пишет |
| `FileReportChannel`, `TelegramReportChannel` | `Neo\Logger\Channels` | Каналы отправки исключений |
| `ReportDriverContract`, `ReportChannelContract` | | Контракты драйвера и канала |

## Конфигурация

Верхний уровень `LoggerTransfer`: `default` (имя драйвера), `drivers`, `channels`. Ключи драйвера и канала проверяет менеджер при первом обращении, а не при создании transfer.

**Драйверы** (`drivers[имя]`):

| Ключ | Для чего | Смысл |
|---|---|---|
| `driver` | все | `single`, `daily`, `monthly`, `stack`, `disabled` или имя из `extend()` |
| `path` | single, daily, monthly | Обязателен. Для daily и monthly дата вставляется перед расширением: `app.log` → `app-2026-11-05.log` |
| `level` | все | Минимальный уровень, по умолчанию `debug` |
| `drivers` | stack | Непустой список имён других драйверов |

**Каналы** (`channels[имя]`):

| Ключ | Для чего | Смысл |
|---|---|---|
| `channel` | все | `file`, `telegram` или имя из `extendChannel()`. По умолчанию равен имени канала |
| `driver` | file | Имя драйвера, в который писать (по умолчанию `default`) |
| `level` | file | Уровень записи исключения, по умолчанию `error` |
| `token`, `chat_id` | telegram | Обязательны |
| `app`, `timeout` | telegram | Префикс сообщения и таймаут в секундах (по умолчанию 3) |

## API

**Уровни** (от важного к менее важному): `emergency`, `alert`, `critical`, `error`, `warning`, `notice`, `info`, `debug`. Запись ниже минимального `level` драйвера отбрасывается молча.

**Менеджер и фасад:**

- `emergency|alert|critical|error|warning|notice|info|debug($message, $context = [])` и `log($level, ...)` пишут в драйвер по умолчанию.
- `driver(?string $name)` возвращает настроенный драйвер (кешируется).
- `stack(array $names, array $config = [])` собирает временный стек из настроенных драйверов.
- `build(array $config)` создаёт драйвер на лету, не регистрируя его.
- `extend($type, Closure $factory)` добавляет тип драйвера. Фабрика получает `(array $config, LogManager $manager)`.
- `channel(string $name)` возвращает канал.
- `extendChannel($type, Closure $factory)` добавляет тип канала, сигнатура фабрики та же.
- `report(Throwable $e, ?array $channels = null)` отправляет исключение в указанные каналы (по умолчанию во все).

## Поведение

- **Плейсхолдеры.** `{key}` в сообщении заменяется значением из контекста, если оно скаляр или `Stringable`. Остальное остаётся как есть.
- **Контекст.** Исключения превращаются в массив (`class`, `message`, `code`, `file`, `line`, `trace`), объекты без `__toString` в имя класса, ресурсы в `resource(type)`. При невалидном UTF-8 запись не теряется: проблемные значения заменяются на `null`.
- **Запись.** Дописывание с `LOCK_EX`, каталог создаётся автоматически. Если записать нельзя, бросается `RuntimeException`.
- **Стек.** Сбой одного драйвера не мешает остальным: исключение пробрасывается после обхода всех.
- **`report()`.** Сбои каналов гасятся, репортёр не должен ронять приложение и скрывать исходную ошибку.
- **Telegram.** Токен не попадает в текст исключения. Нужен `allow_url_fopen = On`.

## Исключения

Все ошибки конфигурации это `InvalidArgumentException`: неизвестный драйвер или канал, нет `driver`, нет `path`, пустой или циклический стек, неверный уровень. Ошибки записи это `RuntimeException`.

## Расширение

```php
Log::extend('syslog', fn (array $config, LogManager $log) => new SyslogDriver($config));
Log::extendChannel('slack', fn (array $config) => new SlackChannel($config));
```

Свой драйвер проще всего наследовать от `BasicReportDriver` и реализовать `write()`: уровни, фильтр и плейсхолдеры уже сделаны.

## Тесты

Запуск: `php cli test`. Файлы в `neo/Logger/Tests`, общие хелперы в `TestHelpers.php` (`logger_tmp_dir`, `logger_read`, `logger_manager`, `logger_spy`). Файлы пишутся во временный каталог, который удаляется при завершении.

## Для ИИ и новых разработчиков

- Драйверы и каналы **не** биндятся в контейнер: они принимают массив конфигурации, их создаёт `LogManager`.
- `LogManager` в контейнере это синглтон с `LoggerTransfer`; регистрируется в Bootstrap приложения.
- Новый файловый драйвер наследует `FileReportDriver` и реализует только `resolvePath()` (для суффиксов есть `suffixedPath()`).
- Новый тип драйвера и канала можно подключить через `extend()` и `extendChannel()` без правки менеджера.
- Все namespace начинаются с `Neo\` (с заглавной буквы).