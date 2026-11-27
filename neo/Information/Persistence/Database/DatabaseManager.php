<?php

declare(strict_types=1);

namespace Neo\Information\Persistence\Database;

use Closure;
use Neo\Foundation\Runtime\Context;
use Neo\Foundation\Runtime\Pool\Pool;
use Neo\Foundation\Runtime\Pool\PoolOptions;
use Neo\Foundation\Runtime\Runtime;
use Neo\Information\Persistence\Database\Connectors\Connector;
use Neo\Information\Persistence\Database\Exceptions\DatabaseException;
use Neo\Information\Persistence\Database\Query\Builder;
use Neo\Information\Persistence\Database\Query\Grammar;
use Neo\Information\Persistence\Database\Query\Raw;
use Neo\Information\Persistence\Database\Schema\SchemaBuilder;
use Throwable;

/**
 * Менеджер соединений. Всё, чего нет в нём, проксируется в соединение по умолчанию,
 * поэтому `DB::table()`, `DB::select()`, `DB::transaction()` работают как в Laravel.
 *
 * Два режима, выбираются автоматически по рантайму и не требуют правок кода:
 *
 *  - обычный (sync, FPM, консоль): одно соединение на имя на весь процесс;
 *  - пул (корутины Swoole, `requiresConnectionPool`): соединение берётся из пула воркера при первом
 *    обращении внутри корутины, живёт в её контексте и возвращается в пул в конце запроса.
 *    Две одновременные корутины никогда не делят одно соединение и одну транзакцию.
 *
 * @mixin Connection
 */
class DatabaseManager
{
    private const string CONTEXT_PREFIX = 'neo.db.connection.';

    /** @var array<string, Connection> */
    private array $connections = [];

    /** @var array<string, Pool> пулы принадлежат воркеру и создаются лениво, уже после fork */
    private array $pools = [];

    /** @var list<Closure(string, array, float): void> */
    private array $listeners = [];

    /** @param array{default?: string, pool?: array, connections?: array<string, array>} $config */
    public function __construct(private array $config, private readonly Connector $connector = new Connector()) {}

    public function connection(?string $name = null): Connection
    {
        $name ??= $this->getDefaultConnection();

        if ($this->shouldPool($name)) {
            return $this->pooled($name);
        }

        return $this->connections[$name] ??= $this->make($name);
    }

    public function getDefaultConnection(): string
    {
        return (string) ($this->config['default'] ?? 'sqlite');
    }

    public function setDefaultConnection(string $name): void
    {
        $this->config['default'] = $name;
    }

    public function purge(?string $name = null): void
    {
        unset($this->connections[$name ?? $this->getDefaultConnection()]);
    }

    public function extend(string $name, array $config): void
    {
        $this->config['connections'][$name] = $config;
        unset($this->connections[$name]);
    }

    /**
     * Слушатель SQL-запросов для всех соединений: текущих, будущих и пулов.
     *
     * @param Closure(string, array, float): void $listener
     */
    public function listen(Closure $listener): void
    {
        $this->listeners[] = $listener;

        foreach ($this->connections as $connection) {
            $connection->listen($listener);
        }
    }

    // ------------------------------------------------------------------
    // Жизненный цикл воркера (вызывает Lifecycle, см. DatabaseServiceProvider)
    // ------------------------------------------------------------------

    /**
     * Воркер стартует: всё, что открыл master-процесс до fork, воркеру не принадлежит.
     * Соединения забываются без закрытия, чтобы не оборвать сессию, которую ещё держит родитель.
     */
    public function forgetInherited(): void
    {
        $this->connections = [];
        $this->pools = [];
    }

    /**
     * Подгоняет размер пула под лимит соединений сервера MySQL. Вызывается один раз в master-процессе
     * до запуска воркеров (`php cli serve --swoole`).
     *
     * Пул создаётся в КАЖДОМ воркере, поэтому на сервер приходит `workers × pool.max` соединений.
     * Если это больше `max_connections`, клиенты получают «Too many connections». Здесь максимум
     * на воркер уменьшается до `floor((max_connections - reserve) / workers)`. Увеличивать значение
     * не нужно, поэтому настройка только ограничивается сверху. `reserve` (DB_POOL_RESERVE) оставляет
     * место для консоли, миграций, сидера и других клиентов.
     *
     * Проверяется только соединение по умолчанию и только драйвер mysql. Если сервер недоступен,
     * размер остаётся как в конфиге.
     *
     * @return array<string, array{configured: int, applied: int, server_max: int, reserve: int, workers: int}>
     */
    public function fitPools(int $workers): array
    {
        $workers = max(1, $workers);
        $name = $this->getDefaultConnection();
        $connection = (array) ($this->config['connections'][$name] ?? []);
        $pool = $this->poolConfig($name);

        if ($pool === null || ($connection['driver'] ?? null) !== 'mysql') {
            return [];
        }

        try {
            $probe = $this->make($name);
            $serverMax = (int) ($probe->selectOne('SELECT @@max_connections AS m')['m'] ?? 0);
            unset($probe); # соединение закрывается: master не должен занимать слот сервера
        } catch (Throwable) {
            return [];
        }

        if ($serverMax < 1) {
            return [];
        }

        $reserve = max(0, (int) ($pool['reserve'] ?? 20));
        $configured = max(1, (int) ($pool['max'] ?? 10));
        $applied = min($configured, max(1, intdiv($serverMax - $reserve, $workers)));

        if ($applied < $configured) {
            $pool['max'] = $applied;
            $pool['min'] = min((int) ($pool['min'] ?? 0), $applied);

            if (isset($this->config['connections'][$name]['pool'])) {
                $this->config['connections'][$name]['pool'] = $pool;
            } else {
                $this->config['pool'] = $pool;
            }
        }

        return [$name => [
            'configured' => $configured,
            'applied' => $applied,
            'server_max' => $serverMax,
            'reserve' => $reserve,
            'workers' => $workers,
        ]];
    }

    /** Прогрев пулов до `min` (только внутри корутины: иначе канал ожидания недоступен). */
    public function warmUp(): void
    {
        if (!Runtime::current()->inCoroutine()) {
            return;
        }

        foreach (array_keys((array) ($this->config['connections'] ?? [])) as $name) {
            $config = $this->poolConfig((string) $name);

            if ($config !== null && (int) ($config['min'] ?? 0) > 0) {
                $this->pool((string) $name)->warmUp();
            }
        }
    }

    /** Возвращает в пул соединения текущей корутины (конец запроса). Повторный вызов безопасен. */
    public function releaseCurrent(): void
    {
        foreach ($this->pools as $name => $pool) {
            $this->release($name, $pool);
        }
    }

    /** Graceful shutdown воркера: закрывает пулы и соединения. */
    /**
     * Воркер получил команду на выход (max_request, reload), но запросы ещё идут: закрываем только простаивающие
     * соединения и таймеры пула. Выданные соединения вернутся в пул штатно, окончательно всё закроет close().
     */
    public function drain(): void
    {
        foreach ($this->pools as $pool) {
            $pool->drain();
        }
    }

    public function close(): void
    {
        foreach ($this->pools as $pool) {
            $pool->close();
        }

        $this->pools = [];
        $this->connections = [];
    }

    /** @return array<string, array{total: int, idle: int, leased: int, max: int}> */
    public function poolStats(): array
    {
        return array_map(static fn (Pool $pool): array => $pool->stats(), $this->pools);
    }

    // ------------------------------------------------------------------

    private function make(string $name): Connection
    {
        $config = $this->config['connections'][$name]
            ?? throw new DatabaseException("Соединение БД [{$name}] не настроено.");

        $driver = (string) ($config['driver'] ?? '');

        $grammar = new Grammar($driver === 'mysql' ? '`' : '"');

        $connection = new Connection($this->connector->connect($config), $config, $name, $grammar);

        foreach ($this->listeners as $listener) {
            $connection->listen($listener);
        }

        return $connection;
    }

    private function shouldPool(string $name): bool
    {
        $runtime = Runtime::current();

        return $runtime->capabilities()->requiresConnectionPool()
            && $runtime->inCoroutine()
            && $this->poolConfig($name) !== null;
    }

    /** @return array<string, mixed>|null null: для этого соединения пул не используется */
    private function poolConfig(string $name): ?array
    {
        $connection = (array) ($this->config['connections'][$name] ?? []);
        $pool = (array) ($connection['pool'] ?? $this->config['pool'] ?? []);

        if (($pool['enabled'] ?? true) === false) {
            return null;
        }

        # sqlite в памяти: у каждого соединения своя, отдельная база, делить её через пул нельзя.
        if (($connection['driver'] ?? null) === 'sqlite' && in_array($connection['database'] ?? ':memory:', [':memory:', ''], true)) {
            return null;
        }

        return $pool;
    }

    private function pool(string $name): Pool
    {
        if (isset($this->pools[$name])) {
            return $this->pools[$name];
        }

        $pool = new Pool(
            factory: fn (): Connection => $this->make($name),
            options: PoolOptions::fromArray($this->poolConfig($name) ?? []),
            runtime: Runtime::current(),
            validate: static fn (Connection $connection): bool => $connection->ping(),
            reset: function (Connection $connection): void {
                $connection->reset();

                foreach ($this->listeners as $listener) {
                    $connection->listen($listener);
                }
            },
        );

        # Фоновая чистка простаивающих соединений (в sync-рантайме таймеров нет, вызов ничего не делает).
        $pool->startReaper();

        return $this->pools[$name] = $pool;
    }

    private function pooled(string $name): Connection
    {
        $key = self::CONTEXT_PREFIX . $name;
        $current = Context::get($key);

        if ($current instanceof Connection) {
            return $current;
        }

        $pool = $this->pool($name);

        /** @var Connection $connection */
        $connection = $pool->acquire();
        Context::set($key, $connection);

        # Подстраховка: корутина завершилась без releaseCurrent() (задача, go(), исключение).
        Runtime::current()->defer(fn () => $this->release($name, $pool, $connection));

        return $connection;
    }

    private function release(string $name, Pool $pool, ?Connection $expected = null): void
    {
        $key = self::CONTEXT_PREFIX . $name;
        $connection = Context::get($key);

        # Соединение уже вернули (releaseCurrent) или оно чужое: второй возврат испортил бы пул.
        if (!$connection instanceof Connection || ($expected !== null && $connection !== $expected)) {
            return;
        }

        Context::destroy($key);
        $pool->release($connection, $connection->isBroken());
    }

    public function table(string $table): Builder
    {
        return $this->connection()->table($table);
    }

    public function schema(?string $connection = null): SchemaBuilder
    {
        return $this->connection($connection)->schema();
    }

    public function raw(string $value): Raw
    {
        return new Raw($value);
    }

    public function __call(string $method, array $args): mixed
    {
        return $this->connection()->{$method}(...$args);
    }
}
