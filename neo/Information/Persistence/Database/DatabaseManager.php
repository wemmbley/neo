<?php

declare(strict_types=1);

namespace Neo\Information\Persistence\Database;

use Neo\Information\Persistence\Database\Connectors\Connector;
use Neo\Information\Persistence\Database\Exceptions\DatabaseException;
use Neo\Information\Persistence\Database\Query\Builder;
use Neo\Information\Persistence\Database\Query\Grammar;
use Neo\Information\Persistence\Database\Query\Raw;
use Neo\Information\Persistence\Database\Schema\SchemaBuilder;

/**
 * Менеджер соединений. Всё, чего нет в нём, проксируется в соединение по умолчанию,
 * поэтому `DB::table()`, `DB::select()`, `DB::transaction()` работают как в Laravel.
 *
 * @mixin Connection
 */
class DatabaseManager
{
    /** @var array<string, Connection> */
    private array $connections = [];

    /** @param array{default?: string, connections?: array<string, array>} $config */
    public function __construct(private array $config, private readonly Connector $connector = new Connector()) {}

    public function connection(?string $name = null): Connection
    {
        $name ??= $this->getDefaultConnection();

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

    private function make(string $name): Connection
    {
        $config = $this->config['connections'][$name]
            ?? throw new DatabaseException("Соединение БД [{$name}] не настроено.");

        $driver = (string) ($config['driver'] ?? '');

        $grammar = new Grammar($driver === 'mysql' ? '`' : '"');

        return new Connection($this->connector->connect($config), $config, $name, $grammar);
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
