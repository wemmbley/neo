<?php

declare(strict_types=1);

use Neo\Config\Env;

beforeEach(function () {
    Env::flush();

    $this->directory = sys_get_temp_dir()
        . DIRECTORY_SEPARATOR
        . 'neo-env-' . bin2hex(random_bytes(6));

    mkdir($this->directory, 0777, true);
});

afterEach(function () {
    Env::flush();

    $removeDirectory = function (string $directory) use (&$removeDirectory): void {
        foreach (scandir($directory) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $path = $directory . DIRECTORY_SEPARATOR . $entry;

            if (is_dir($path) && !is_link($path)) {
                $removeDirectory($path);
            } else {
                unlink($path);
            }
        }

        rmdir($directory);
    };

    if (is_dir($this->directory)) {
        $removeDirectory($this->directory);
    }
});

function removeEnvTestDirectory(string $directory): void
{
    foreach (glob($directory . DIRECTORY_SEPARATOR . '*') ?: [] as $file) {
        if (is_dir($file)) {
            removeEnvTestDirectory($file);
        } else {
            unlink($file);
        }
    }

    rmdir($directory);
}

describe('Canonical names', function () {
    it('converts names to uppercase', function () {
        expect(Env::canonical('app_debug'))
            ->toBe('APP_DEBUG');
    });

    it('replaces dots with underscores', function () {
        expect(Env::canonical('db.host'))
            ->toBe('DB_HOST');
    });

    it('normalizes mixed case and separators', function () {
        expect(Env::canonical('Db.Host'))
            ->toBe('DB_HOST');
    });

    it('preserves existing underscores', function () {
        expect(Env::canonical('db_host'))
            ->toBe('DB_HOST');
    });
});

describe('Parsing environment contents', function () {
    it('parses simple key-value pairs', function () {
        $result = Env::parse(
            "APP_NAME=Neo\nAPP_DEBUG=true\nDB_HOST=127.0.0.1"
        );

        expect($result)->toBe([
            'APP_NAME' => 'Neo',
            'APP_DEBUG' => 'true',
            'DB_HOST' => '127.0.0.1',
        ]);
    });

    it('ignores empty lines', function () {
        $result = Env::parse(
            "APP_NAME=Neo\n\n\nAPP_DEBUG=true\n"
        );

        expect($result)->toBe([
            'APP_NAME' => 'Neo',
            'APP_DEBUG' => 'true',
        ]);
    });

    it('ignores full-line comments', function () {
        $result = Env::parse(
            "# Application\nAPP_NAME=Neo\n# Database\nDB_HOST=localhost"
        );

        expect($result)->toBe([
            'APP_NAME' => 'Neo',
            'DB_HOST' => 'localhost',
        ]);
    });

    it('supports export declarations', function () {
        $result = Env::parse(
            "export APP_NAME=Neo\nexport DB_HOST=localhost"
        );

        expect($result)->toBe([
            'APP_NAME' => 'Neo',
            'DB_HOST' => 'localhost',
        ]);
    });

    it('supports keys containing dots', function () {
        $result = Env::parse(
            "db.host=localhost\ndb.password=secret"
        );

        expect($result)->toBe([
            'db.host' => 'localhost',
            'db.password' => 'secret',
        ]);
    });

    it('trims unquoted values', function () {
        $result = Env::parse('APP_NAME=   Neo   ');

        expect($result['APP_NAME'])->toBe('Neo');
    });

    it('removes inline comments from unquoted values', function () {
        $result = Env::parse(
            "APP_NAME=Neo # application name\nAPP_DEBUG=true   # debug mode"
        );

        expect($result)->toBe([
            'APP_NAME' => 'Neo',
            'APP_DEBUG' => 'true',
        ]);
    });

    it('preserves hash characters without preceding whitespace', function () {
        $result = Env::parse('APP_URL=https://example.com/#section');

        expect($result['APP_URL'])
            ->toBe('https://example.com/#section');
    });

    it('parses double-quoted values', function () {
        $result = Env::parse(
            'APP_NAME="Neo Framework"'
        );

        expect($result['APP_NAME'])->toBe('Neo Framework');
    });

    it('preserves spaces inside double quotes', function () {
        $result = Env::parse(
            'DB_PASSWORD="secret with spaces"'
        );

        expect($result['DB_PASSWORD'])
            ->toBe('secret with spaces');
    });

    it('parses single-quoted values literally', function () {
        $result = Env::parse(
            "APP_VALUE='literal value'"
        );

        expect($result['APP_VALUE'])->toBe('literal value');
    });

    it('does not expand variables inside single quotes', function () {
        $result = Env::parse(
            "APP_URL='\${DOMAIN}/shop'"
        );

        expect($result['APP_URL'])->toBe('${DOMAIN}/shop');
    });

    it('supports multiline double-quoted values', function () {
        $result = Env::parse(
            "APP_DESCRIPTION=\"First line\nSecond line\nThird line\""
        );

        expect($result['APP_DESCRIPTION'])
            ->toBe("First line\nSecond line\nThird line");
    });

    it('supports multiline single-quoted values', function () {
        $result = Env::parse(
            "APP_DESCRIPTION='First line\nSecond line'"
        );

        expect($result['APP_DESCRIPTION'])
            ->toBe("First line\nSecond line");
    });

    it('processes escaped newlines in double quotes', function () {
        $result = Env::parse(
            'APP_VALUE="First\nSecond"'
        );

        expect($result['APP_VALUE'])
            ->toBe("First\nSecond");
    });

    it('processes escaped carriage returns in double quotes', function () {
        $result = Env::parse(
            'APP_VALUE="First\rSecond"'
        );

        expect($result['APP_VALUE'])
            ->toBe("First\rSecond");
    });

    it('processes escaped tabs in double quotes', function () {
        $result = Env::parse(
            'APP_VALUE="First\tSecond"'
        );

        expect($result['APP_VALUE'])
            ->toBe("First\tSecond");
    });

    it('processes escaped quotes', function () {
        $result = Env::parse(
            'APP_VALUE="say \"hello\""'
        );

        expect($result['APP_VALUE'])->toBe('say "hello"');
    });

    it('processes escaped backslashes', function () {
        $result = Env::parse(
            'APP_PATH="C:\\\\Neo\\\\App"'
        );

        expect($result['APP_PATH'])->toBe('C:\Neo\App');
    });

    it('processes escaped dollar signs', function () {
        $result = Env::parse(
            'APP_VALUE="Price: \$100"'
        );

        expect($result['APP_VALUE'])->toBe('Price: $100');
    });

    it('allows comments after closing quotes', function () {
        $result = Env::parse(
            'APP_NAME="Neo Framework" # application name'
        );

        expect($result['APP_NAME'])->toBe('Neo Framework');
    });

    it('removes a UTF-8 BOM before parsing', function () {
        $result = Env::parse(
            "\xEF\xBB\xBFAPP_NAME=Neo"
        );

        expect($result)->toBe([
            'APP_NAME' => 'Neo',
        ]);
    });

    it('uses the last declaration when a key is duplicated', function () {
        $result = Env::parse(
            "APP_NAME=First\nAPP_NAME=Second"
        );

        expect($result)->toBe([
            'APP_NAME' => 'Second',
        ]);
    });

    it('throws an exception for an invalid line', function () {
        expect(fn () => Env::parse(
            "APP_NAME=Neo\nINVALID LINE",
            'custom.env'
        ))->toThrow(
            RuntimeException::class,
            'custom.env, строка 2: ожидается КЛЮЧ=значение.'
        );
    });

    it('throws an exception when a double quote is not closed', function () {
        expect(fn () => Env::parse(
            "APP_NAME=Neo\nAPP_VALUE=\"unfinished",
            'custom.env'
        ))->toThrow(
            RuntimeException::class,
            'custom.env, строка 2: не закрыта кавычка.'
        );
    });

    it('throws an exception when a single quote is not closed', function () {
        expect(fn () => Env::parse(
            "APP_VALUE='unfinished"
        ))->toThrow(RuntimeException::class);
    });

    it('throws an exception when there is text after a closing quote', function () {
        expect(fn () => Env::parse(
            'APP_NAME="Neo" unexpected',
            'custom.env'
        ))->toThrow(
            RuntimeException::class,
            'custom.env, строка 1: после закрывающей кавычки есть лишний текст.'
        );
    });

    it('includes the original line number for multiline errors', function () {
        expect(fn () => Env::parse(
            "APP_NAME=Neo\n\nAPP_VALUE=\"unfinished",
            'custom.env'
        ))->toThrow(
            RuntimeException::class,
            'custom.env, строка 3: не закрыта кавычка.'
        );
    });
});

describe('Variable interpolation', function () {
    it('expands a variable declared earlier in the file', function () {
        $result = Env::parse(
            "DOMAIN=example.com\nAPP_URL=https://\${DOMAIN}/shop"
        );

        expect($result['APP_URL'])
            ->toBe('https://example.com/shop');
    });

    it('expands variables inside double quotes', function () {
        $result = Env::parse(
            "DOMAIN=example.com\nAPP_URL=\"https://\${DOMAIN}/shop\""
        );

        expect($result['APP_URL'])
            ->toBe('https://example.com/shop');
    });

    it('supports dotted variable names during interpolation', function () {
        $result = Env::parse(
            "db.host=localhost\nAPP_URL=\${db.host}"
        );

        expect($result['APP_URL'])->toBe('localhost');
    });

    it('uses the latest previous declaration during interpolation', function () {
        $result = Env::parse(
            "DOMAIN=first.com\nDOMAIN=second.com\nAPP_URL=\${DOMAIN}"
        );

        expect($result['APP_URL'])->toBe('second.com');
    });

    it('replaces an undefined variable with an empty string', function () {
        $result = Env::parse(
            'APP_URL=https://${UNKNOWN}/shop'
        );

        expect($result['APP_URL'])->toBe('https:///shop');
    });

    it('does not expand malformed interpolation expressions', function () {
        $result = Env::parse(
            'APP_VALUE=${INVALID-NAME}'
        );

        expect($result['APP_VALUE'])->toBe('${INVALID-NAME}');
    });
});

describe('Loading files', function () {
    it('returns false when the file does not exist', function () {
        expect(Env::load($this->directory . '/missing.env'))
            ->toBeFalse();
    });

    it('loads an existing environment file', function () {
        $path = $this->directory . '/.env';

        file_put_contents($path, "APP_NAME=Neo\nAPP_DEBUG=true");

        expect(Env::load($path))->toBeTrue()
            ->and(Env::raw('APP_NAME'))->toBe('Neo')
            ->and(Env::raw('APP_DEBUG'))->toBe('true');
    });

    it('returns true when an empty file is loaded', function () {
        $path = $this->directory . '/.env';

        file_put_contents($path, '');

        expect(Env::load($path))->toBeTrue();
    });

    it('throws when the file contains invalid syntax', function () {
        $path = $this->directory . '/.env';

        file_put_contents($path, "APP_NAME=Neo\nINVALID");

        expect(fn () => Env::load($path))
            ->toThrow(RuntimeException::class);
    });

    it('normalizes loaded keys', function () {
        $path = $this->directory . '/.env';

        file_put_contents($path, 'db.host=localhost');

        Env::load($path);

        expect(Env::raw('DB_HOST'))->toBe('localhost')
            ->and(Env::raw('db.host'))->toBe('localhost')
            ->and(Env::raw('Db_Host'))->toBe('localhost');
    });

    it('does not overwrite an existing server variable by default', function () {
        $key = 'NEO_TEST_' . strtoupper(bin2hex(random_bytes(5)));

        putenv("{$key}=server-value");

        try {
            $path = $this->directory . '/.env';
            file_put_contents($path, "{$key}=file-value");

            Env::load($path);

            expect(Env::raw($key))->toBe('server-value');
        } finally {
            putenv($key);
        }
    });

    it('overwrites an existing server variable when overwrite is true', function () {
        $key = 'NEO_TEST_' . strtoupper(bin2hex(random_bytes(5)));

        putenv("{$key}=server-value");

        try {
            $path = $this->directory . '/.env';
            file_put_contents($path, "{$key}=file-value");

            Env::load($path, true);

            expect(Env::raw($key))->toBe('file-value');
        } finally {
            putenv($key);
        }
    });

    it('uses previously loaded values during interpolation', function () {
        $path = $this->directory . '/.env';

        file_put_contents(
            $path,
            "NEO_TEST_DOMAIN=example.com\nNEO_TEST_URL=https://\${NEO_TEST_DOMAIN}"
        );

        Env::load($path);

        expect(Env::raw('NEO_TEST_URL'))
            ->toBe('https://example.com');
    });
});

describe('Getting values', function () {
    it('returns a raw string value', function () {
        Env::set('APP_NAME', 'Neo');

        expect(Env::get('APP_NAME'))->toBe('Neo');
    });

    it('returns the default when the key is missing', function () {
        expect(Env::get('MISSING_KEY', 'fallback'))
            ->toBe('fallback');
    });

    it('evaluates a closure default when the key is missing', function () {
        $called = false;

        $result = Env::get('MISSING_KEY', function () use (&$called) {
            $called = true;

            return 'generated';
        });

        expect($result)->toBe('generated')
            ->and($called)->toBeTrue();
    });

    it('does not evaluate a closure default when the key exists', function () {
        Env::set('APP_NAME', 'Neo');

        $called = false;

        $result = Env::get('APP_NAME', function () use (&$called) {
            $called = true;

            return 'generated';
        });

        expect($result)->toBe('Neo')
            ->and($called)->toBeFalse();
    });

    it('converts true to boolean true', function () {
        Env::set('APP_DEBUG', 'true');

        expect(Env::get('APP_DEBUG'))->toBeTrue();
    });

    it('converts false to boolean false', function () {
        Env::set('APP_DEBUG', 'false');

        expect(Env::get('APP_DEBUG'))->toBeFalse();
    });

    it('supports parenthesized boolean values', function () {
        Env::set('APP_TRUE', '(true)');
        Env::set('APP_FALSE', '(false)');

        expect(Env::get('APP_TRUE'))->toBeTrue()
            ->and(Env::get('APP_FALSE'))->toBeFalse();
    });

    it('converts null to null', function () {
        Env::set('APP_VALUE', 'null');

        expect(Env::get('APP_VALUE'))->toBeNull();
    });

    it('supports parenthesized null', function () {
        Env::set('APP_VALUE', '(null)');

        expect(Env::get('APP_VALUE'))->toBeNull();
    });

    it('converts empty to an empty string', function () {
        Env::set('APP_VALUE', 'empty');

        expect(Env::get('APP_VALUE'))->toBe('');
    });

    it('supports parenthesized empty', function () {
        Env::set('APP_VALUE', '(empty)');

        expect(Env::get('APP_VALUE'))->toBe('');
    });

    it('preserves ordinary strings', function () {
        Env::set('APP_VALUE', 'some-value');

        expect(Env::get('APP_VALUE'))->toBe('some-value');
    });

    it('converts special values case-insensitively', function () {
        Env::set('APP_TRUE', 'TRUE');
        Env::set('APP_FALSE', 'FALSE');
        Env::set('APP_NULL', 'NULL');
        Env::set('APP_EMPTY', 'EMPTY');

        expect(Env::get('APP_TRUE'))->toBeTrue()
            ->and(Env::get('APP_FALSE'))->toBeFalse()
            ->and(Env::get('APP_NULL'))->toBeNull()
            ->and(Env::get('APP_EMPTY'))->toBe('');
    });
});

describe('Getting raw values', function () {
    it('returns a string without type conversion', function () {
        Env::set('APP_DEBUG', 'true');

        expect(Env::raw('APP_DEBUG'))->toBe('true');
    });

    it('returns null for a missing key', function () {
        expect(Env::raw('MISSING_KEY'))->toBeNull();
    });

    it('returns a custom default for a missing key', function () {
        expect(Env::raw('MISSING_KEY', 'fallback'))
            ->toBe('fallback');
    });

    it('preserves the literal string null', function () {
        Env::set('APP_VALUE', 'null');

        expect(Env::raw('APP_VALUE'))->toBe('null');
    });
});

describe('Checking existence', function () {
    it('returns true for an existing value', function () {
        Env::set('APP_NAME', 'Neo');

        expect(Env::has('APP_NAME'))->toBeTrue();
    });

    it('returns false for a missing value', function () {
        expect(Env::has('MISSING_KEY'))->toBeFalse();
    });

    it('returns true for the literal string null', function () {
        Env::set('APP_VALUE', 'null');

        expect(Env::has('APP_VALUE'))->toBeTrue();
    });
});

describe('Boolean values', function () {
    it('recognizes true values', function () {
        foreach (['1', 'true', '(true)', 'yes', 'on'] as $value) {
            Env::set('APP_BOOL', $value);

            expect(Env::bool('APP_BOOL'))
                ->toBeTrue();
        }
    });

    it('recognizes false values', function () {
        foreach (['0', 'false', '(false)', 'no', 'off', '', 'null', '(null)', 'empty', '(empty)'] as $value) {
            Env::set('APP_BOOL', $value);

            expect(Env::bool('APP_BOOL'))
                ->toBeFalse();
        }
    });

    it('ignores case and surrounding whitespace', function () {
        Env::set('APP_BOOL', '  YES  ');

        expect(Env::bool('APP_BOOL'))->toBeTrue();
    });

    it('returns the default for a missing key', function () {
        expect(Env::bool('MISSING_KEY'))->toBeFalse()
            ->and(Env::bool('MISSING_KEY', true))->toBeTrue();
    });

    it('returns the default for an unrecognized value', function () {
        Env::set('APP_BOOL', 'maybe');

        expect(Env::bool('APP_BOOL'))->toBeFalse()
            ->and(Env::bool('APP_BOOL', true))->toBeTrue();
    });
});

describe('Integer values', function () {
    it('converts a positive integer', function () {
        Env::set('APP_PORT', '8080');

        expect(Env::int('APP_PORT'))->toBe(8080);
    });

    it('converts a negative integer', function () {
        Env::set('APP_VALUE', '-42');

        expect(Env::int('APP_VALUE'))->toBe(-42);
    });

    it('accepts surrounding whitespace', function () {
        Env::set('APP_PORT', ' 8080 ');

        expect(Env::int('APP_PORT'))->toBe(8080);
    });

    it('returns the default for a missing key', function () {
        expect(Env::int('MISSING_KEY'))->toBe(0)
            ->and(Env::int('MISSING_KEY', 25))->toBe(25);
    });

    it('returns the default for a non-integer value', function () {
        Env::set('APP_PORT', '8080.5');

        expect(Env::int('APP_PORT'))->toBe(0)
            ->and(Env::int('APP_PORT', 25))->toBe(25);
    });

    it('returns the default for an alphanumeric value', function () {
        Env::set('APP_PORT', '8080abc');

        expect(Env::int('APP_PORT', 25))->toBe(25);
    });

    it('accepts zero', function () {
        Env::set('APP_PORT', '0');

        expect(Env::int('APP_PORT'))->toBe(0);
    });
});

describe('Setting values', function () {
    it('sets a value manually', function () {
        Env::set('APP_NAME', 'Neo');

        expect(Env::raw('APP_NAME'))->toBe('Neo');
    });

    it('normalizes the key when setting a value', function () {
        Env::set('db.host', 'localhost');

        expect(Env::raw('DB_HOST'))->toBe('localhost')
            ->and(Env::raw('Db_Host'))->toBe('localhost');
    });

    it('overwrites an existing value', function () {
        Env::set('APP_NAME', 'First');
        Env::set('APP_NAME', 'Second');

        expect(Env::raw('APP_NAME'))->toBe('Second');
    });
});

describe('Flushing values', function () {
    it('removes manually set values', function () {
        Env::set('APP_NAME', 'Neo');

        Env::flush();

        expect(Env::raw('APP_NAME'))->toBeNull();
    });

    it('removes values loaded from files', function () {
        $path = $this->directory . '/.env';

        file_put_contents($path, 'APP_NAME=Neo');

        Env::load($path);
        Env::flush();

        expect(Env::raw('APP_NAME'))->toBeNull();
    });

    it('does not remove real server environment variables', function () {
        $key = 'NEO_TEST_' . strtoupper(bin2hex(random_bytes(5)));

        putenv("{$key}=server-value");

        try {
            Env::flush();

            expect(Env::raw($key))->toBe('server-value');
        } finally {
            putenv($key);
        }
    });
});

describe('Writing to files', function () {
    it('creates a new environment file', function () {
        $path = $this->directory . '/.env';

        Env::writeToFile($path, 'APP_NAME', 'Neo');

        expect(is_file($path))->toBeTrue()
            ->and(file_get_contents($path))->toBe("APP_NAME=Neo\n");
    });

    it('writes a simple value without quotes', function () {
        $path = $this->directory . '/.env';

        Env::writeToFile($path, 'APP_PORT', '8080');

        expect(file_get_contents($path))->toBe("APP_PORT=8080\n");
    });

    it('quotes values containing spaces', function () {
        $path = $this->directory . '/.env';

        Env::writeToFile($path, 'DB_PASSWORD', 'secret with spaces');

        expect(file_get_contents($path))
            ->toBe('DB_PASSWORD="secret with spaces"' . "\n");
    });

    it('quotes values containing special characters', function () {
        $path = $this->directory . '/.env';

        Env::writeToFile($path, 'APP_VALUE', 'value#with spaces');

        expect(file_get_contents($path))
            ->toBe('APP_VALUE="value#with spaces"' . "\n");
    });

    it('updates the in-memory value after writing', function () {
        $path = $this->directory . '/.env';

        Env::writeToFile($path, 'APP_NAME', 'Neo');

        expect(Env::raw('APP_NAME'))->toBe('Neo');
    });

    it('replaces an existing key', function () {
        $path = $this->directory . '/.env';

        file_put_contents(
            $path,
            "APP_NAME=Old\nAPP_DEBUG=true\n"
        );

        Env::writeToFile($path, 'APP_NAME', 'New');

        expect(file_get_contents($path))
            ->toBe("APP_NAME=New\nAPP_DEBUG=true\n");
    });

    it('replaces an existing key with a different canonical representation', function () {
        $path = $this->directory . '/.env';

        file_put_contents(
            $path,
            "db.host=localhost\nAPP_DEBUG=true\n"
        );

        Env::writeToFile($path, 'DB_HOST', '127.0.0.1');

        expect(file_get_contents($path))
            ->toBe("DB_HOST=127.0.0.1\nAPP_DEBUG=true\n");
    });

    it('replaces an exported declaration', function () {
        $path = $this->directory . '/.env';

        file_put_contents(
            $path,
            "export APP_NAME=Old\nAPP_DEBUG=true\n"
        );

        Env::writeToFile($path, 'APP_NAME', 'New');

        expect(file_get_contents($path))
            ->toBe("APP_NAME=New\nAPP_DEBUG=true\n");
    });

    it('preserves unrelated lines and comments', function () {
        $path = $this->directory . '/.env';

        file_put_contents(
            $path,
            "# Application settings\nAPP_NAME=Old\n\n# Debug mode\nAPP_DEBUG=true\n"
        );

        Env::writeToFile($path, 'APP_NAME', 'New');

        expect(file_get_contents($path))
            ->toBe("# Application settings\nAPP_NAME=New\n\n# Debug mode\nAPP_DEBUG=true\n");
    });

    it('appends a new key without replacing existing keys', function () {
        $path = $this->directory . '/.env';

        file_put_contents($path, "APP_NAME=Neo\n");

        Env::writeToFile($path, 'APP_DEBUG', 'true');

        expect(file_get_contents($path))
            ->toBe("APP_NAME=Neo\nAPP_DEBUG=true\n");
    });

    it('does not create duplicate trailing empty lines', function () {
        $path = $this->directory . '/.env';

        file_put_contents($path, "APP_NAME=Neo\n\n");

        Env::writeToFile($path, 'APP_DEBUG', 'true');

        expect(file_get_contents($path))
            ->toBe("APP_NAME=Neo\n\nAPP_DEBUG=true\n");
    });

    it('writes and reads back a value containing quotes and backslashes', function () {
        $path = $this->directory . '/.env';
        $value = 'say "hello" at C:\Neo\App';

        Env::writeToFile($path, 'APP_VALUE', $value);

        Env::flush();
        Env::load($path);

        expect(Env::raw('APP_VALUE'))->toBe($value);
    });

    it('writes and reads back a value containing a dollar sign', function () {
        $path = $this->directory . '/.env';
        $value = 'Price: $100';

        Env::writeToFile($path, 'APP_VALUE', $value);

        Env::flush();
        Env::load($path);

        expect(Env::raw('APP_VALUE'))->toBe($value);
    });

    it('writes and reads back a multiline value', function () {
        $path = $this->directory . '/.env';
        $value = "First line\nSecond line";

        Env::writeToFile($path, 'APP_VALUE', $value);

        Env::flush();
        Env::load($path);

        expect(Env::raw('APP_VALUE'))->toBe($value);
    });
});
