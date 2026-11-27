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
            # Относительный путь (DB_DATABASE=app/Database/Database.sqlite) считается от корня проекта,
            # а не от текущей папки процесса: у php-fpm, Swoole и cron она разная.
            if (!str_starts_with($path, '/') && !str_starts_with($path, '\\') && preg_match('~^[A-Za-z]:[\\\\/]~', $path) !== 1) {
                $path = base_path($path);
            }

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

        $options = self::OPTIONS;

        # Клиентская подстановка параметров: запрос уходит на сервер за один круг вместо двух-трёх
        # (серверный prepare + execute + close). Значения экранирует PDO с учётом charset из DSN.
        # Несколько запросов в одной строке при этом запрещаем, чтобы подстановка не открывала stacked queries.
        if (filter_var($c['emulate_prepares'] ?? true, FILTER_VALIDATE_BOOL)) {
            $options[PDO::ATTR_EMULATE_PREPARES] = true;

            foreach (['Pdo\\Mysql::ATTR_MULTI_STATEMENTS', 'PDO::MYSQL_ATTR_MULTI_STATEMENTS'] as $constant) {
                if (defined($constant)) {
                    $options[constant($constant)] = false;

                    break;
                }
            }
        }

        $pdo = new PDO($dsn, $c['username'] ?? null, $c['password'] ?? null, $options);

        $set = "SET NAMES '{$charset}'";
        if (!empty($c['collation'])) {
            $set .= " COLLATE '" . $c['collation'] . "'";
        }
        $pdo->exec($set);

        # Часовой пояс и sql_mode одним запросом: на каждое новое соединение пула одним кругом до сервера меньше.
        $assign = [];

        if (!empty($c['timezone'])) {
            $assign[] = "time_zone = '" . $c['timezone'] . "'";
        }

        $assign[] = ($c['strict'] ?? true)
            ? "sql_mode = 'ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION'"
            : "sql_mode = 'NO_ENGINE_SUBSTITUTION'";

        $pdo->exec('SET ' . implode(', ', $assign));

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
