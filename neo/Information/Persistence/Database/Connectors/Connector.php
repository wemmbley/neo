<?php

declare(strict_types=1);

namespace Neo\Information\Persistence\Database\Connectors;

use Neo\Information\Persistence\Database\Exceptions\DatabaseException;
use PDO;

/**
 * Создаёт PDO по конфигу соединения (sqlite, mysql).
 */
final class Connector
{
    private const OPTIONS = [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
        PDO::ATTR_STRINGIFY_FETCHES => false,
    ];

    public function connect(array $config): PDO
    {
        $config = $this->expandUrl($config);

        return match ($config['driver'] ?? null) {
            'sqlite' => $this->sqlite($config),
            'mysql' => $this->mysql($config),
            default => throw new DatabaseException(
                'Неподдерживаемый драйвер БД: ' . var_export($config['driver'] ?? null, true)
            ),
        };
    }

    private function sqlite(array $c): PDO
    {
        $path = (string) ($c['database'] ?? ':memory:');

        if ($path !== ':memory:' && !str_starts_with($path, 'file:')) {
            $dir = dirname($path);

            if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
                throw new DatabaseException("Не удалось создать папку базы: {$dir}");
            }

            if (!is_file($path)) {
                touch($path);
            }
        }

        $pdo = new PDO('sqlite:' . $path, null, null, self::OPTIONS);

        if (($c['foreign_key_constraints'] ?? true)) {
            $pdo->exec('PRAGMA foreign_keys = ON');
        }
        if (($c['busy_timeout'] ?? null) !== null) {
            $pdo->exec('PRAGMA busy_timeout = ' . (int) $c['busy_timeout']);
        }
        if (($c['journal_mode'] ?? null) !== null) {
            $pdo->exec('PRAGMA journal_mode = ' . preg_replace('/[^A-Za-z]/', '', (string) $c['journal_mode']));
        }
        if (($c['synchronous'] ?? null) !== null) {
            $pdo->exec('PRAGMA synchronous = ' . preg_replace('/[^A-Za-z0-9]/', '', (string) $c['synchronous']));
        }

        return $pdo;
    }

    private function mysql(array $c): PDO
    {
        $charset = (string) ($c['charset'] ?? 'utf8mb4');

        if (!empty($c['unix_socket'])) {
            $dsn = 'mysql:unix_socket=' . $c['unix_socket'] . ';dbname=' . $c['database'];
        } else {
            $dsn = 'mysql:host=' . ($c['host'] ?? '127.0.0.1')
                . ';port=' . ($c['port'] ?? 3306)
                . ';dbname=' . ($c['database'] ?? '');
        }

        $dsn .= ';charset=' . $charset;

        $pdo = new PDO($dsn, $c['username'] ?? null, $c['password'] ?? null, self::OPTIONS);

        $set = "SET NAMES '{$charset}'";
        if (!empty($c['collation'])) {
            $set .= " COLLATE '" . $c['collation'] . "'";
        }
        $pdo->exec($set);

        if (!empty($c['timezone'])) {
            $pdo->exec("SET time_zone = '" . $c['timezone'] . "'");
        }

        $pdo->exec(($c['strict'] ?? true)
            ? "SET SESSION sql_mode = 'ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION'"
            : "SET SESSION sql_mode = 'NO_ENGINE_SUBSTITUTION'");

        return $pdo;
    }

    /** `url` вида mysql://user:pass@host:3306/db переопределяет остальные поля. */
    private function expandUrl(array $c): array
    {
        if (empty($c['url'])) {
            return $c;
        }

        $p = parse_url((string) $c['url']);
        if ($p === false) {
            throw new DatabaseException('Некорректный DB_URL.');
        }

        $map = [
            'host' => 'host',
            'port' => 'port',
            'user' => 'username',
            'pass' => 'password',
        ];
        foreach ($map as $from => $to) {
            if (isset($p[$from])) {
                $c[$to] = urldecode((string) $p[$from]);
            }
        }
        if (isset($p['path']) && $p['path'] !== '') {
            $c['database'] = ltrim(urldecode($p['path']), '/');
        }
        if (isset($p['scheme'])) {
            $c['driver'] = $p['scheme'] === 'sqlite3' ? 'sqlite' : $p['scheme'];
        }

        return $c;
    }
}
