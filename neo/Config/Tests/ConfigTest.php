<?php

declare(strict_types=1);

use Neo\Config\Config;

beforeEach(function () {
    $this->config = new Config([
        'app' => [
            'name' => 'Neo',
            'debug' => true,
            'timezone' => 'Europe/Kyiv',
        ],
        'database' => [
            'default' => 'mysql',
            'connections' => [
                'mysql' => [
                    'host' => 'localhost',
                    'port' => 3306,
                ],
            ],
        ],
        'empty' => null,
    ]);
});

describe('Construction', function () {
    it('creates an empty configuration by default', function () {
        $config = new Config();

        expect($config->all())->toBe([]);
    });

    it('stores the provided configuration', function () {
        expect($this->config->all())->toBe([
            'app' => [
                'name' => 'Neo',
                'debug' => true,
                'timezone' => 'Europe/Kyiv',
            ],
            'database' => [
                'default' => 'mysql',
                'connections' => [
                    'mysql' => [
                        'host' => 'localhost',
                        'port' => 3306,
                    ],
                ],
            ],
            'empty' => null,
        ]);
    });
});

describe('Getting values', function () {
    it('gets a top-level value', function () {
        expect($this->config->get('app'))
            ->toBe([
                'name' => 'Neo',
                'debug' => true,
                'timezone' => 'Europe/Kyiv',
            ]);
    });

    it('gets a nested value using dot notation', function () {
        expect($this->config->get('app.name'))
            ->toBe('Neo');

        expect($this->config->get('database.connections.mysql.host'))
            ->toBe('localhost');
    });

    it('returns the default when the key does not exist', function () {
        expect($this->config->get('app.version', '1.0.0'))
            ->toBe('1.0.0');
    });

    it('returns null when the key does not exist and no default is provided', function () {
        expect($this->config->get('app.version'))
            ->toBeNull();
    });

    it('returns the default when a nested path is missing', function () {
        expect($this->config->get('database.connections.pgsql.host', '127.0.0.1'))
            ->toBe('127.0.0.1');
    });

    it('returns null for an existing key containing null', function () {
        expect($this->config->get('empty'))
            ->toBeNull();
    });

    it('supports different value types', function () {
        expect($this->config->get('app.debug'))->toBeTrue()
            ->and($this->config->get('database.connections.mysql.port'))->toBe(3306)
            ->and($this->config->get('missing', false))->toBeFalse();
    });
});

describe('Checking keys', function () {
    it('detects existing top-level keys', function () {
        expect($this->config->has('app'))->toBeTrue()
            ->and($this->config->has('database'))->toBeTrue();
    });

    it('detects existing nested keys', function () {
        expect($this->config->has('app.name'))->toBeTrue()
            ->and($this->config->has('database.connections.mysql.host'))->toBeTrue();
    });

    it('returns false for missing keys', function () {
        expect($this->config->has('app.version'))->toBeFalse()
            ->and($this->config->has('database.connections.pgsql'))->toBeFalse();
    });

    it('returns true for existing keys containing null', function () {
        expect($this->config->has('empty'))->toBeTrue();
    });
});

describe('Setting values', function () {
    it('sets a top-level value', function () {
        $this->config->set('version', '1.0.0');

        expect($this->config->get('version'))->toBe('1.0.0');
    });

    it('sets a nested value', function () {
        $this->config->set('app.name', 'Neo Framework');

        expect($this->config->get('app.name'))
            ->toBe('Neo Framework');
    });

    it('creates missing nested paths', function () {
        $this->config->set('cache.stores.file.path', '/tmp/cache');

        expect($this->config->get('cache.stores.file.path'))
            ->toBe('/tmp/cache');
    });

    it('overwrites an existing value', function () {
        $this->config->set('app.debug', false);

        expect($this->config->get('app.debug'))->toBeFalse();
    });

    it('sets a value to null', function () {
        $this->config->set('app.name', null);

        expect($this->config->has('app.name'))->toBeTrue()
            ->and($this->config->get('app.name'))->toBeNull();
    });

    it('preserves unrelated configuration values', function () {
        $this->config->set('app.name', 'Neo Framework');

        expect($this->config->get('app.debug'))->toBeTrue()
            ->and($this->config->get('app.timezone'))->toBe('Europe/Kyiv')
            ->and($this->config->get('database.default'))->toBe('mysql');
    });
});

describe('Getting all configuration', function () {
    it('returns the complete configuration array', function () {
        expect($this->config->all())
            ->toBeArray()
            ->toHaveKey('app')
            ->toHaveKey('database');
    });

    it('returns the updated configuration after setting values', function () {
        $this->config->set('app.name', 'Updated');

        expect($this->config->all()['app']['name'])
            ->toBe('Updated');
    });
});

describe('Loading directory', function () {
    beforeEach(function () {
        $this->directory = sys_get_temp_dir()
            . DIRECTORY_SEPARATOR
            . 'neo-config-' . uniqid('', true);

        mkdir($this->directory, 0777, true);
    });

    afterEach(function () {
        foreach (glob($this->directory . DIRECTORY_SEPARATOR . '*') ?: [] as $file) {
            if (is_file($file)) {
                unlink($file);
            } elseif (is_dir($file)) {
                // На случай появления вложенных директорий.
                removeDirectory($file);
            }
        }

        if (is_dir($this->directory)) {
            rmdir($this->directory);
        }
    });

    function removeDirectory(string $directory): void
    {
        foreach (glob($directory . DIRECTORY_SEPARATOR . '*') ?: [] as $file) {
            if (is_dir($file)) {
                removeDirectory($file);
            } else {
                unlink($file);
            }
        }

        rmdir($directory);
    }

    it('returns an empty array when the directory does not exist', function () {
        expect(Config::loadDirectory($this->directory . '/missing'))
            ->toBe([]);
    });

    it('returns an empty array when the directory contains no PHP files', function () {
        expect(Config::loadDirectory($this->directory))
            ->toBe([]);
    });

    it('loads PHP configuration files', function () {
        file_put_contents(
            $this->directory . '/app.php',
            '<?php return ["name" => "Neo", "debug" => true];'
        );

        file_put_contents(
            $this->directory . '/database.php',
            '<?php return ["default" => "mysql"];'
        );

        expect(Config::loadDirectory($this->directory))
            ->toBe([
                'app' => [
                    'name' => 'Neo',
                    'debug' => true,
                ],
                'database' => [
                    'default' => 'mysql',
                ],
            ]);
    });

    it('uses the filename as the configuration namespace', function () {
        file_put_contents(
            $this->directory . '/view.php',
            '<?php return ["cache_path" => "/tmp/views"];'
        );

        $loaded = Config::loadDirectory($this->directory);

        expect($loaded)
            ->toHaveKey('view')
            ->and($loaded['view']['cache_path'])
            ->toBe('/tmp/views');
    });

    it('loads files in alphabetical order', function () {
        file_put_contents(
            $this->directory . '/zebra.php',
            '<?php return ["name" => "zebra"];'
        );

        file_put_contents(
            $this->directory . '/app.php',
            '<?php return ["name" => "app"];'
        );

        file_put_contents(
            $this->directory . '/database.php',
            '<?php return ["name" => "database"];'
        );

        expect(array_keys(Config::loadDirectory($this->directory)))
            ->toBe(['app', 'database', 'zebra']);
    });

    it('ignores files that do not have the PHP extension', function () {
        file_put_contents($this->directory . '/readme.txt', 'Not a config file');

        file_put_contents(
            $this->directory . '/app.php',
            '<?php return ["name" => "Neo"];'
        );

        expect(Config::loadDirectory($this->directory))
            ->toBe([
                'app' => ['name' => 'Neo'],
            ]);
    });

    it('throws an exception when a configuration file does not return an array', function () {
        $file = $this->directory . '/invalid.php';

        file_put_contents($file, '<?php return "invalid";');

        expect(fn () => Config::loadDirectory($this->directory))
            ->toThrow(
                UnexpectedValueException::class,
                "Файл настроек {$file} должен возвращать массив."
            );
    });

    it('accepts an empty array returned by a configuration file', function () {
        file_put_contents(
            $this->directory . '/empty.php',
            '<?php return [];'
        );

        expect(Config::loadDirectory($this->directory))
            ->toBe(['empty' => []]);
    });

    it('trims trailing directory separators', function () {
        file_put_contents(
            $this->directory . '/app.php',
            '<?php return ["name" => "Neo"];'
        );

        expect(Config::loadDirectory($this->directory . DIRECTORY_SEPARATOR))
            ->toBe([
                'app' => ['name' => 'Neo'],
            ]);
    });
});
