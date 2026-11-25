<?php

declare(strict_types=1);

namespace Neo\Foundation\Kernel\Commands;

use Neo\Foundation\Config\Config;
use Neo\Foundation\Console\Commands\Command;
use Neo\Foundation\Kernel\Requirements;
use PDO;
use Throwable;

/**
 * Проверка окружения: версия PHP, расширения, .env, права на папки и файлы, настройки БД.
 *
 *   php cli validate                 только проблемы и итог
 *   php cli validate -v              плюс всё, что в порядке
 *   php cli validate --user=www-data права проверяются и для пользователя веб-сервера
 *   php cli validate --strict        предупреждения тоже дают ненулевой код выхода
 *
 * Команда не поднимает провайдеры (disableMounting) и запускается даже тогда, когда не хватает
 * расширений или версии PHP: Bootstrap пропускает для неё обычную проверку requires.
 *
 * Консоль обычно работает от одного пользователя, а веб-сервер (php-fpm, nginx, swoole) от другого,
 * поэтому права на запись проверяются дважды: для текущего пользователя и для пользователя веб-сервера.
 */
class ValidateCommand extends Command
{
    protected string $signature = 'validate {--v|verbose : Показать и успешные проверки} {--user= : Пользователь веб-сервера для проверки прав (например www-data)} {--strict : Предупреждения тоже считать ошибкой}';

    protected string $description = 'Проверяет PHP, расширения, .env, права на папки и настройки БД';

    public bool $disableMounting = true;

    /** Кого считаем пользователем веб-сервера, если --user не указан. */
    private const array WEB_USERS = ['www-data', 'nginx', 'apache', 'http', 'www', '_www', 'nobody'];

    private const string OK = 'ok';
    private const string WARN = 'warn';
    private const string FAIL = 'fail';

    /** @var list<array{level: string, group: string, text: string, hint: ?string}> */
    private array $results = [];

    private string $group = '';

    /** @var list<array{name: string, uid: int, gid: int, gids: list<int>, explicit: bool}> */
    private array $actors = [];

    /** @var array<string, true> пути, которым нужно исправить права (для подсказки chown/chmod) */
    private array $fixPaths = [];

    public function __construct(private readonly Config $config)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $this->results = [];
        $this->fixPaths = [];
        $this->actors = $this->detectActors();

        $this->checkPhp();
        $this->checkEnvironment();
        $this->checkPaths();
        $this->checkDatabase();
        $this->checkRuntime();
        $this->checkSecurity();

        return $this->report();
    }

    // ------------------------------------------------------------------
    // PHP
    // ------------------------------------------------------------------

    private function checkPhp(): void
    {
        $this->group = 'PHP';

        $bootstrap = base_path('app/Config/Bootstrap.php');
        $requires = is_file($bootstrap) ? (array) (require $bootstrap)['requires'] : [];

        $this->pass('Версия ' . PHP_VERSION . ' (' . PHP_SAPI . ', ' . (php_ini_loaded_file() ?: 'php.ini не найден') . ')');

        if ($requires === []) {
            $this->notice('В app/Config/Bootstrap.php нет секции requires: версия и расширения не проверены.');
        }

        # Версия и расширения из requires: те же правила, что у Bootstrap.
        foreach ($requires as $key => $value) {
            if ($key === 'php_ext') {
                foreach ((array) $value as $extension) {
                    $extension = (string) $extension;

                    extension_loaded($extension)
                        ? $this->pass("Расширение {$extension}")
                        : $this->problem(
                            "Не подключено расширение {$extension}.",
                            'Установите пакет php-' . strtolower($extension) . ' или добавьте extension=' . strtolower($extension) . ' в php.ini.',
                        );
                }

                continue;
            }

            if (is_int($key)) {
                $problems = Requirements::problems([$value]);

                $problems === []
                    ? $this->pass("Требование {$value}")
                    : $this->problem(ucfirst($problems[0]) . '.', 'Обновите PHP до нужной версии.');
            }
        }

        # Расширения, зависящие от настроек приложения.
        $default = (string) $this->config->get('database.default', 'sqlite');
        $driver = (string) $this->config->get("database.connections.{$default}.driver", $default);
        $needed = match ($driver) {
            'mysql' => 'pdo_mysql',
            'sqlite' => 'pdo_sqlite',
            default => null,
        };

        if ($needed !== null && !extension_loaded($needed)) {
            $this->problem("Соединение по умолчанию [{$default}] использует {$driver}, а расширение {$needed} не подключено.", "Подключите {$needed} в php.ini.");
        }

        $cache = (string) $this->config->get('cache.default', 'file');

        if ($cache !== 'file' && $cache !== 'array') {
            $this->notice("Драйвер кэша [{$cache}] неизвестен ядру.");
        }

        # Настройки php.ini.
        $memory = $this->bytes((string) ini_get('memory_limit'));

        if ($memory !== -1 && $memory < 128 * 1024 * 1024) {
            $this->notice('memory_limit = ' . ini_get('memory_limit') . ': меньше 128M.', 'Поднимите memory_limit в php.ini.');
        }

        if (!extension_loaded('mbstring')) {
            $this->notice('Без mbstring ломается кириллица в командах и шаблонах.');
        }

        $production = $this->isProduction();

        if (extension_loaded('Zend OPcache') && $production && !filter_var(ini_get('opcache.enable'), FILTER_VALIDATE_BOOL)) {
            $this->notice('OPcache выключен на боевом окружении.', 'Включите opcache.enable=1 для веб-SAPI.');
        } elseif (!extension_loaded('Zend OPcache') && $production) {
            $this->notice('OPcache не установлен: на боевом окружении PHP будет заметно медленнее.');
        }

        if ($production && filter_var(ini_get('display_errors'), FILTER_VALIDATE_BOOL)) {
            $this->notice('display_errors включён на боевом окружении: ошибки видны посетителям.', 'Поставьте display_errors=Off в php.ini веб-SAPI.');
        }

        if (extension_loaded('swoole') || extension_loaded('openswoole')) {
            $this->pass('Swoole/OpenSwoole доступен: работает php cli serve --swoole');
        } else {
            $this->pass('Swoole не установлен (необязателен: php cli serve работает без него)');
        }
    }

    // ------------------------------------------------------------------
    // .env, ключ, режим
    // ------------------------------------------------------------------

    private function checkEnvironment(): void
    {
        $this->group = 'Окружение';

        $env = base_path('.env');

        if (!is_file($env)) {
            $this->problem('Нет файла .env.', 'Скопируйте .env.example в .env: cp .env.example .env');
        } else {
            is_readable($env)
                ? $this->pass('.env найден')
                : $this->problem('.env не читается текущим пользователем.', $this->fixHint($env));

            $this->checkActorsRead($env, '.env');
        }

        $key = (string) env('APP_KEY', '');

        $key === ''
            ? $this->problem('APP_KEY пуст: шифрование, куки и сессии небезопасны.', 'Выполните: php cli key:generate')
            : $this->pass('APP_KEY задан');

        $name = trim((string) env('APP_URL', ''));

        if ($name === '') {
            $this->notice('APP_URL пуст.', 'Задайте APP_URL в .env (используется для ссылок и публичных файлов).');
        }

        if ($this->isProduction() && filter_var(env('APP_DEBUG', false), FILTER_VALIDATE_BOOL)) {
            $this->problem('APP_ENV=production, но APP_DEBUG=true: трассировки ошибок видны посетителям.', 'Поставьте APP_DEBUG=false в .env.');
        } else {
            $this->pass('APP_ENV=' . (string) env('APP_ENV', 'production') . ', APP_DEBUG=' . var_export((bool) filter_var(env('APP_DEBUG', false), FILTER_VALIDATE_BOOL), true));
        }

        $tz = date_default_timezone_get();

        $tz === 'UTC'
            ? $this->pass('Часовой пояс UTC')
            : $this->notice("Часовой пояс {$tz}: ядро рассчитано на UTC.");
    }

    // ------------------------------------------------------------------
    // Папки и файлы
    // ------------------------------------------------------------------

    private function checkPaths(): void
    {
        $this->group = 'Права на папки';

        foreach ($this->actors as $actor) {
            $this->pass('Проверка прав для: ' . $actor['name'] . ($actor['explicit'] ? '' : ' (определён автоматически)'));
        }

        # [путь, зачем, нужна ли запись]
        $dirs = [
            [base_path('app/Storage/Logs'), 'логи (Logger)'],
            [base_path('app/Storage/Private'), 'диск local и файловый кэш'],
            [base_path('app/Storage/Public'), 'диск public'],
        ];

        if ((string) $this->config->get('session.driver', 'database') === 'file') {
            $dirs[] = [(string) $this->config->get('session.files', base_path('app/Storage/Sessions')), 'файловые сессии'];
        }

        if (filter_var($this->config->get('view.cache.enabled', false), FILTER_VALIDATE_BOOL)) {
            $dirs[] = [(string) $this->config->get('view.cache.path', base_path('app/Storage/Cache/UI/Views')), 'кэш шаблонов'];
        }

        foreach ($dirs as [$dir, $purpose]) {
            $this->checkWritableDir($dir, $purpose);
        }

        # Логи: уже существующий файл за сегодня тоже должен быть доступен на запись.
        $logs = base_path('app/Storage/Logs');

        if (is_dir($logs)) {
            foreach (glob($logs . '/*.log') ?: [] as $file) {
                $this->checkWritableFile($file, 'лог-файл');
            }
        }

        # Папки, из которых только читают.
        foreach ([
            [(string) $this->config->get('view.basic_path', base_path('src/UI/Views')), 'шаблоны'],
            [(string) $this->config->get('view.resources.path', base_path('src/UI/Resources')), 'ресурсы'],
        ] as [$dir, $purpose]) {
            if (!is_dir($dir)) {
                $this->notice("Нет папки {$this->rel($dir)} ({$purpose}).");

                continue;
            }

            $this->checkReadable($dir, $purpose);
        }

        # Симлинки публичных файлов (storage:link).
        foreach ((array) $this->config->get('storage.links', []) as $target => $link) {
            if (is_link((string) $link)) {
                $this->pass('Ссылка ' . $this->rel((string) $link));
            } elseif (file_exists((string) $link)) {
                $this->notice('На месте ссылки ' . $this->rel((string) $link) . ' лежит обычный файл или папка.');
            } else {
                $this->notice(
                    'Нет ссылки ' . $this->rel((string) $link) . ' -> ' . $this->rel((string) $target) . '.',
                    'Создайте её командой storage:link (если она есть) или: ln -s ' . $target . ' ' . $link,
                );
            }
        }

        $public = base_path('public');

        is_file($public . '/index.php')
            ? $this->pass('public/index.php найден')
            : $this->problem('Нет public/index.php: веб-серверу нечего запускать.');
    }

    private function checkWritableDir(string $dir, string $purpose): void
    {
        $label = $this->rel($dir) . " ({$purpose})";

        if (!is_dir($dir)) {
            # Папки нет: приложение попробует создать её само, значит нужна запись в ближайшего существующего родителя.
            $parent = $this->nearestExisting($dir);

            if ($parent === null) {
                $this->problem("Папки {$label} нет и создать её негде.");

                return;
            }

            $writable = is_writable($parent);

            foreach ($this->actors as $actor) {
                $writable = $writable && $this->can($parent, $actor, 2 | 1);
            }

            $writable
                ? $this->pass("Папки {$label} нет, но она будет создана")
                : $this->problem("Папки {$label} нет, а в {$this->rel($parent)} писать нельзя.", $this->fixHint($parent));

            return;
        }

        $this->checkAccess($dir, $label, 2 | 1);
    }

    private function checkWritableFile(string $file, string $purpose): void
    {
        $this->checkAccess($file, $this->rel($file) . " ({$purpose})", 2);
    }

    private function checkReadable(string $path, string $purpose): void
    {
        $this->checkAccess($path, $this->rel($path) . " ({$purpose})", 4);
    }

    private function checkActorsRead(string $path, string $label): void
    {
        foreach ($this->actors as $actor) {
            if (!$this->can($path, $actor, 4)) {
                $this->failForActor($actor, "{$label} недоступен для чтения", $path);
            }
        }
    }

    /** Проверка доступа текущим пользователем и пользователем веб-сервера. $bits: 4 чтение, 2 запись, 1 вход/выполнение. */
    private function checkAccess(string $path, string $label, int $bits): void
    {
        $verb = ($bits & 2) !== 0 ? 'запись' : 'чтение';
        $fine = true;

        $own = (($bits & 2) === 0 || is_writable($path)) && (($bits & 4) === 0 || is_readable($path));

        if (!$own) {
            $fine = false;
            $this->problem("{$label}: нет прав на {$verb} для текущего пользователя (" . $this->currentUser() . ').', $this->fixHint($path));
        }

        foreach ($this->actors as $actor) {
            if (!$this->can($path, $actor, $bits)) {
                $fine = false;
                $this->failForActor($actor, "{$label}: нет прав на {$verb}", $path);
            }
        }

        if ($fine) {
            $this->pass("{$label}: " . (($bits & 2) !== 0 ? "запись доступна" : "чтение доступно"));
        }
    }

    private function failForActor(array $actor, string $message, string $path): void
    {
        $text = "{$message} для {$actor['name']}.";
        $hint = $this->fixHint($path, $actor['name']);

        # Автоопределённый веб-пользователь может быть не тем, под кем реально работает сервер: только предупреждение.
        $actor['explicit'] ? $this->problem($text, $hint) : $this->notice($text . ' Если веб-сервер работает от другого пользователя, укажите --user=имя.', $hint);
    }

    /** Запоминает путь для итоговой команды chown/chmod; на месте проблемы достаточно короткой отсылки. */
    private function fixHint(string $path, ?string $user = null): string
    {
        $this->fixPaths[$this->fixRoot($path)] = true;

        return 'Исправляется командой chown/chmod в конце отчёта.';
    }

    /** Что чинить: сама папка, а для файла её папка. */
    private function fixRoot(string $path): string
    {
        return is_dir($path) ? $path : dirname($path);
    }

    // ------------------------------------------------------------------
    // База данных
    // ------------------------------------------------------------------

    private function checkDatabase(): void
    {
        $this->group = 'База данных';

        $name = (string) $this->config->get('database.default', 'sqlite');
        $c = (array) $this->config->get("database.connections.{$name}", []);

        if ($c === []) {
            $this->problem("Соединение по умолчанию [{$name}] не описано в app/Config/Database.php.");

            return;
        }

        $driver = (string) ($c['driver'] ?? '');

        if ($driver === 'sqlite') {
            $this->checkSqlite((string) ($c['database'] ?? ''));
        } elseif ($driver === 'mysql') {
            $this->checkMysql($c);
        } else {
            $this->problem("Неподдерживаемый драйвер БД: {$driver}.");

            return;
        }

        if ((string) $this->config->get('session.driver', 'database') === 'database') {
            $this->pass('Сессии хранятся в БД: таблица ' . (string) $this->config->get('session.table', 'sessions') . ' должна быть создана миграцией (php cli migrate, если команда есть).');
        }

        $min = (int) $this->config->get('database.pool.min', 1);
        $max = (int) $this->config->get('database.pool.max', 10);

        if ($min > $max) {
            $this->problem("Пул БД: DB_POOL_MIN ({$min}) больше DB_POOL_MAX ({$max}).");
        }
    }

    private function checkSqlite(string $path): void
    {
        if ($path === '') {
            $this->problem('Для sqlite не задан путь (DB_DATABASE).');

            return;
        }

        if ($path === ':memory:' || str_starts_with($path, 'file:')) {
            $this->pass("sqlite: {$path}");

            return;
        }

        # Относительный путь считается от корня проекта (так же, как делает Connector).
        $absolute = $this->isAbsolute($path) ? $path : base_path($path);
        $dir = dirname($absolute);

        if (!$this->isAbsolute($path)) {
            $this->pass("DB_DATABASE задан относительным путём ({$path}): он считается от корня проекта");
        }

        $this->checkWritableDir($dir, 'папка базы sqlite');

        if (is_file($absolute)) {
            $this->checkAccess($absolute, $this->rel($absolute) . ' (файл базы)', 4 | 2);
        } else {
            $this->notice('Файла базы ' . $this->rel($absolute) . ' ещё нет: он будет создан при первом обращении.');
        }

        if (!extension_loaded('pdo_sqlite')) {
            return;
        }

        if (is_file($absolute) && is_readable($absolute)) {
            try {
                $pdo = new PDO('sqlite:' . $absolute, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
                $pdo->query('SELECT 1')->fetchColumn();
                $this->pass('sqlite открывается и отвечает');
            } catch (Throwable $e) {
                $this->problem('sqlite не открывается: ' . $e->getMessage());
            }
        }
    }

    /** @param array<string, mixed> $c */
    private function checkMysql(array $c): void
    {
        if (!extension_loaded('pdo_mysql')) {
            return;
        }

        if (empty($c['unix_socket']) && trim((string) ($c['host'] ?? '')) === '') {
            $this->problem('Для mysql не задан DB_HOST.');

            return;
        }

        $dsn = !empty($c['unix_socket'])
            ? 'mysql:unix_socket=' . $c['unix_socket'] . ';dbname=' . ($c['database'] ?? '')
            : 'mysql:host=' . ($c['host'] ?? '127.0.0.1') . ';port=' . ($c['port'] ?? 3306) . ';dbname=' . ($c['database'] ?? '');

        try {
            $pdo = new PDO($dsn, (string) ($c['username'] ?? ''), (string) ($c['password'] ?? ''), [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_TIMEOUT => 3,
            ]);
            $pdo->query('SELECT 1')->fetchColumn();
            $this->pass('mysql: соединение установлено');
        } catch (Throwable $e) {
            $this->problem('mysql: не удалось подключиться: ' . $e->getMessage(), 'Проверьте DB_HOST, DB_PORT, DB_DATABASE, DB_USERNAME, DB_PASSWORD в .env.');
        }
    }

    // ------------------------------------------------------------------
    // Swoole и безопасность
    // ------------------------------------------------------------------

    private function checkRuntime(): void
    {
        $this->group = 'Рантайм';

        $workers = $this->config->get('runtime.swoole.workers');

        if ($workers !== null && (int) $workers < 1) {
            $this->problem('SWOOLE_WORKERS должен быть положительным числом или null.');
        }

        $timeout = (float) $this->config->get('runtime.swoole.request_timeout', 0);
        $wait = (int) $this->config->get('runtime.swoole.max_wait_time', 30);

        if ($timeout > 0 && $wait > 0 && $timeout > $wait) {
            $this->notice("request_timeout ({$timeout} с) больше max_wait_time ({$wait} с): при остановке воркер может быть убит раньше, чем закончится запрос.");
        } else {
            $this->pass('Настройки Swoole согласованы');
        }
    }

    private function checkSecurity(): void
    {
        $this->group = 'Безопасность';

        if ($this->isProduction() && (array) $this->config->get('security.trusted_hosts', []) === []) {
            $this->notice('security.trusted_hosts пуст на боевом окружении: нет защиты от подмены Host.', 'Заполните trusted_hosts в app/Config/Security.php.');
        } else {
            $this->pass('trusted_hosts');
        }

        $proxies = $this->config->get('security.proxies', []);

        if ($proxies === '*' || $proxies === ['*']) {
            $this->notice("TRUSTED_PROXIES='*': включайте, только если сервер недоступен напрямую.");
        }

        $env = base_path('.env');

        # .env с секретами не должен быть доступен всем пользователям системы.
        if (is_file($env) && PHP_OS_FAMILY !== 'Windows' && (fileperms($env) & 0004) !== 0) {
            $this->notice('.env читается всеми пользователями системы.', 'chmod 640 .env');
        }

        $public = base_path('public');

        if (is_file($public . '/.env')) {
            $this->problem('.env лежит в публичной папке: секреты доступны из интернета.');
        }
    }

    // ------------------------------------------------------------------
    // Отчёт
    // ------------------------------------------------------------------

    private function report(): int
    {
        $verbose = (bool) $this->option('verbose');
        $fails = 0;
        $warns = 0;
        $groups = [];

        foreach ($this->results as $r) {
            $groups[$r['group']][] = $r;

            match ($r['level']) {
                self::FAIL => $fails++,
                self::WARN => $warns++,
                default => null,
            };
        }

        foreach ($groups as $group => $items) {
            $shown = array_filter($items, static fn (array $r): bool => $r['level'] !== self::OK);

            if ($shown === [] && !$verbose) {
                continue;
            }

            $this->newLine();
            $this->comment($group);

            foreach ($verbose ? $items : $shown as $r) {
                match ($r['level']) {
                    self::OK => $this->info('  ✔ ' . $r['text']),
                    self::WARN => $this->line('  ! ' . $r['text']),
                    self::FAIL => $this->error('  ✘ ' . $r['text']),
                };

                if ($r['hint'] !== null && $r['level'] !== self::OK) {
                    $this->line('      → ' . $r['hint']);
                }
            }
        }

        $this->newLine();

        if ($this->fixPaths !== []) {
            $owner = $this->actors[0]['name'] ?? 'www-data';

            $this->comment("Права одной командой (пользователь веб-сервера: {$owner}, при необходимости замените):");

            $paths = array_keys($this->fixPaths);
            sort($paths);

            $this->line('  chown -R ' . $owner . ' ' . implode(' ', array_map('escapeshellarg', $paths)));
            $this->line('  chmod -R ug+rwX ' . implode(' ', array_map('escapeshellarg', $paths)));
            $this->newLine();
        }

        if ($fails === 0 && $warns === 0) {
            $this->info('Всё в порядке: проблем не найдено.');

            return self::SUCCESS;
        }

        $summary = "Проблем: {$fails}, предупреждений: {$warns}.";

        if ($fails > 0) {
            $this->error($summary);

            return self::FAILURE;
        }

        $this->warn($summary);

        return $this->option('strict') ? self::FAILURE : self::SUCCESS;
    }

    // ------------------------------------------------------------------
    // Пользователи и права
    // ------------------------------------------------------------------

    /** @return list<array{name: string, uid: int, gid: int, gids: list<int>, explicit: bool}> */
    private function detectActors(): array
    {
        if (!function_exists('posix_getpwnam') || PHP_OS_FAMILY === 'Windows') {
            if ($this->option('user')) {
                $this->results[] = ['level' => self::WARN, 'group' => 'PHP', 'text' => 'Расширение posix не подключено: права для --user проверить нельзя.', 'hint' => null];
            }

            return [];
        }

        $explicit = (string) ($this->option('user') ?? '');
        $names = $explicit !== '' ? [$explicit] : self::WEB_USERS;

        foreach ($names as $name) {
            $pw = posix_getpwnam($name);

            if ($pw === false) {
                if ($explicit !== '') {
                    $this->results[] = ['level' => self::FAIL, 'group' => 'PHP', 'text' => "Пользователь {$name} не найден в системе.", 'hint' => null];
                }

                continue;
            }

            # Совпадает с текущим пользователем: отдельной проверки не нужно.
            if ($pw['uid'] === posix_geteuid()) {
                return [];
            }

            return [[
                'name' => $name,
                'uid' => (int) $pw['uid'],
                'gid' => (int) $pw['gid'],
                'gids' => $this->groupsOf($name, (int) $pw['gid']),
                'explicit' => $explicit !== '',
            ]];
        }

        return [];
    }

    /** @return list<int> */
    private function groupsOf(string $name, int $primary): array
    {
        $gids = [$primary];

        if (is_readable('/etc/group')) {
            foreach (file('/etc/group', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
                $parts = explode(':', $line);

                if (isset($parts[2], $parts[3]) && in_array($name, explode(',', $parts[3]), true)) {
                    $gids[] = (int) $parts[2];
                }
            }
        }

        return array_values(array_unique($gids));
    }

    /**
     * Может ли пользователь $actor получить $bits (4 r, 2 w, 1 x) на $path, включая вход во все родительские папки.
     *
     * @param array{uid: int, gid: int, gids: list<int>} $actor
     */
    private function can(string $path, array $actor, int $bits): bool
    {
        if ($actor['uid'] === 0) {
            return true;
        }

        $real = realpath($path) ?: $path;

        # Все родительские каталоги должны пускать (x).
        for ($dir = dirname($real); $dir !== '' && $dir !== dirname($dir); $dir = dirname($dir)) {
            if (!$this->modeAllows($dir, $actor, 1)) {
                return false;
            }
        }

        return $this->modeAllows($real, $actor, $bits);
    }

    /** @param array{uid: int, gid: int, gids: list<int>} $actor */
    private function modeAllows(string $path, array $actor, int $bits): bool
    {
        $st = @stat($path);

        if ($st === false) {
            return false;
        }

        $mode = $st['mode'];

        if ($st['uid'] === $actor['uid']) {
            $shift = 6;
        } elseif (in_array($st['gid'], $actor['gids'], true)) {
            $shift = 3;
        } else {
            $shift = 0;
        }

        # Для обычного файла «x» не нужен, а вот у папки он обязателен для записи.
        if (!is_dir($path)) {
            $bits &= ~1;
        }

        return ((($mode >> $shift) & 7) & $bits) === $bits;
    }

    private function currentUser(): string
    {
        if (function_exists('posix_geteuid') && function_exists('posix_getpwuid')) {
            $pw = posix_getpwuid(posix_geteuid());

            return $pw !== false ? $pw['name'] : (string) posix_geteuid();
        }

        return get_current_user();
    }

    // ------------------------------------------------------------------
    // Мелочи
    // ------------------------------------------------------------------

    private function isProduction(): bool
    {
        return in_array(strtolower((string) env('APP_ENV', 'production')), ['production', 'prod'], true);
    }

    private function isAbsolute(string $path): bool
    {
        return str_starts_with($path, '/') || str_starts_with($path, '\\') || preg_match('~^[A-Za-z]:[\\\\/]~', $path) === 1;
    }

    private function nearestExisting(string $path): ?string
    {
        for ($dir = dirname($path); $dir !== dirname($dir); $dir = dirname($dir)) {
            if (is_dir($dir)) {
                return $dir;
            }
        }

        return is_dir($dir) ? $dir : null;
    }

    private function rel(string $path): string
    {
        $base = rtrim(base_path(), '/\\') . DIRECTORY_SEPARATOR;

        return str_starts_with($path, $base) ? substr($path, strlen($base)) : $path;
    }

    private function bytes(string $value): int
    {
        $value = trim($value);

        if ($value === '' || $value === '-1') {
            return -1;
        }

        $number = (int) $value;

        return match (strtolower(substr($value, -1))) {
            'g' => $number * 1024 ** 3,
            'm' => $number * 1024 ** 2,
            'k' => $number * 1024,
            default => $number,
        };
    }

    private function pass(string $text): void
    {
        $this->results[] = ['level' => self::OK, 'group' => $this->group, 'text' => $text, 'hint' => null];
    }

    private function notice(string $text, ?string $hint = null): void
    {
        $this->results[] = ['level' => self::WARN, 'group' => $this->group, 'text' => $text, 'hint' => $hint];
    }

    private function problem(string $text, ?string $hint = null): void
    {
        $this->results[] = ['level' => self::FAIL, 'group' => $this->group, 'text' => $text, 'hint' => $hint];
    }
}
