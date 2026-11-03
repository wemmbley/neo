<?php

declare(strict_types=1);

use Neo\Config\DotNotation;

describe('Checking keys', function () {
    it('detects an existing top-level key', function () {
        expect(DotNotation::has(['name' => 'Neo'], 'name'))
            ->toBeTrue();
    });

    it('detects an existing nested key', function () {
        $array = [
            'auth' => [
                'errors' => [
                    'failed' => 'Authentication failed',
                ],
            ],
        ];

        expect(DotNotation::has($array, 'auth.errors.failed'))
            ->toBeTrue();
    });

    it('returns true for an existing key containing null', function () {
        expect(DotNotation::has(['value' => null], 'value'))
            ->toBeTrue();
    });

    it('returns false for a missing key', function () {
        expect(DotNotation::has(['name' => 'Neo'], 'email'))
            ->toBeFalse();
    });

    it('returns false when an intermediate segment does not exist', function () {
        expect(DotNotation::has(['auth' => []], 'auth.user.name'))
            ->toBeFalse();
    });

    it('returns false when an intermediate value is not an array', function () {
        expect(DotNotation::has(['auth' => 'invalid'], 'auth.user'))
            ->toBeFalse();
    });
});

describe('Getting values', function () {
    it('gets a top-level value', function () {
        expect(DotNotation::get(['name' => 'Neo'], 'name'))
            ->toBe('Neo');
    });

    it('gets a nested value using dot notation', function () {
        $array = [
            'auth' => [
                'errors' => [
                    'failed' => 'Authentication failed',
                ],
            ],
        ];

        expect(DotNotation::get($array, 'auth.errors.failed'))
            ->toBe('Authentication failed');
    });

    it('returns the entire array for an empty key', function () {
        $array = ['name' => 'Neo', 'version' => '1.0'];

        expect(DotNotation::get($array, ''))
            ->toBe($array);
    });

    it('returns the default for a missing key', function () {
        expect(DotNotation::get(['name' => 'Neo'], 'email', 'unknown'))
            ->toBe('unknown');
    });

    it('returns null when a key is missing and no default is provided', function () {
        expect(DotNotation::get(['name' => 'Neo'], 'email'))
            ->toBeNull();
    });

    it('returns an existing null value instead of the default', function () {
        expect(DotNotation::get(['value' => null], 'value', 'fallback'))
            ->toBeNull();
    });

    it('supports a literal key containing dots', function () {
        $array = [
            'auth.errors.failed' => 'Literal value',
            'auth' => [
                'errors' => [
                    'failed' => 'Nested value',
                ],
            ],
        ];

        expect(DotNotation::get($array, 'auth.errors.failed'))
            ->toBe('Literal value');
    });

    it('prefers a literal dotted key over a nested path', function () {
        $array = [
            'a.b' => 'literal',
            'a' => ['b' => 'nested'],
        ];

        expect(DotNotation::get($array, 'a.b'))
            ->toBe('literal');
    });

    it('returns the default when an intermediate value is not an array', function () {
        expect(DotNotation::get(
            ['auth' => 'invalid'],
            'auth.user.name',
            'fallback'
        ))->toBe('fallback');
    });

    it('supports numeric array indexes', function () {
        $array = [
            'users' => [
                ['name' => 'Anna'],
                ['name' => 'Maria'],
            ],
        ];

        expect(DotNotation::get($array, 'users.0.name'))
            ->toBe('Anna');
    });
});

describe('Setting values', function () {
    it('sets a top-level value', function () {
        $array = [];

        DotNotation::set($array, 'name', 'Neo');

        expect($array)->toBe(['name' => 'Neo']);
    });

    it('sets a nested value', function () {
        $array = [];

        DotNotation::set($array, 'auth.errors.failed', 'Authentication failed');

        expect($array)->toBe([
            'auth' => [
                'errors' => [
                    'failed' => 'Authentication failed',
                ],
            ],
        ]);
    });

    it('creates missing intermediate arrays', function () {
        $array = ['app' => ['name' => 'Neo']];

        DotNotation::set($array, 'cache.stores.file.path', '/tmp/cache');

        expect($array['cache']['stores']['file']['path'])
            ->toBe('/tmp/cache');
    });

    it('overwrites an existing value', function () {
        $array = ['app' => ['name' => 'Old']];

        DotNotation::set($array, 'app.name', 'New');

        expect($array['app']['name'])->toBe('New');
    });

    it('replaces a non-array intermediate value with an array', function () {
        $array = ['app' => 'invalid'];

        DotNotation::set($array, 'app.name', 'Neo');

        expect($array)->toBe([
            'app' => [
                'name' => 'Neo',
            ],
        ]);
    });

    it('replaces a null intermediate value with an array', function () {
        $array = ['app' => null];

        DotNotation::set($array, 'app.name', 'Neo');

        expect($array)->toBe([
            'app' => [
                'name' => 'Neo',
            ],
        ]);
    });

    it('sets a value to null', function () {
        $array = ['app' => ['name' => 'Neo']];

        DotNotation::set($array, 'app.name', null);

        expect(DotNotation::has($array, 'app.name'))->toBeTrue()
            ->and(DotNotation::get($array, 'app.name'))->toBeNull();
    });

    it('preserves unrelated values', function () {
        $array = [
            'app' => [
                'name' => 'Neo',
                'debug' => true,
            ],
            'database' => [
                'driver' => 'mysql',
            ],
        ];

        DotNotation::set($array, 'app.name', 'Updated');

        expect($array['app']['debug'])->toBeTrue()
            ->and($array['database']['driver'])->toBe('mysql');
    });
});

describe('Flattening arrays', function () {
    it('flattens a nested array', function () {
        $array = [
            'auth' => [
                'errors' => [
                    'failed' => 'Failed',
                ],
            ],
        ];

        expect(DotNotation::flatten($array))
            ->toBe([
                'auth.errors.failed' => 'Failed',
            ]);
    });

    it('flattens multiple levels', function () {
        $array = [
            'app' => [
                'name' => 'Neo',
                'cache' => [
                    'enabled' => true,
                ],
            ],
            'debug' => false,
        ];

        expect(DotNotation::flatten($array))
            ->toBe([
                'app.name' => 'Neo',
                'app.cache.enabled' => true,
                'debug' => false,
            ]);
    });

    it('preserves empty arrays as values', function () {
        $array = [
            'app' => [
                'options' => [],
            ],
        ];

        expect(DotNotation::flatten($array))
            ->toBe([
                'app.options' => [],
            ]);
    });

    it('preserves null values', function () {
        $array = [
            'app' => [
                'name' => null,
            ],
        ];

        expect(DotNotation::flatten($array))
            ->toBe([
                'app.name' => null,
            ]);
    });

    it('preserves scalar values', function () {
        $array = [
            'string' => 'value',
            'integer' => 42,
            'boolean' => true,
            'null' => null,
        ];

        expect(DotNotation::flatten($array))
            ->toBe([
                'string' => 'value',
                'integer' => 42,
                'boolean' => true,
                'null' => null,
            ]);
    });

    it('supports a custom prefix', function () {
        $array = [
            'name' => 'Neo',
            'version' => '1.0',
        ];

        expect(DotNotation::flatten($array, 'app'))
            ->toBe([
                'app.name' => 'Neo',
                'app.version' => '1.0',
            ]);
    });

    it('returns an empty array for an empty input', function () {
        expect(DotNotation::flatten([]))->toBe([]);
    });

    it('flattens numeric keys', function () {
        $array = [
            'users' => [
                ['name' => 'Anna'],
                ['name' => 'Maria'],
            ],
        ];

        expect(DotNotation::flatten($array))
            ->toBe([
                'users.0.name' => 'Anna',
                'users.1.name' => 'Maria',
            ]);
    });
});

describe('Expanding wildcards', function () {
    it('expands a wildcard over top-level keys', function () {
        $array = [
            'first' => 'A',
            'second' => 'B',
        ];

        expect(DotNotation::expandWildcard($array, '*'))
            ->toBe(['first', 'second']);
    });

    it('expands a wildcard over nested numeric indexes', function () {
        $array = [
            'tags' => ['php', 'framework', 'neo'],
        ];

        expect(DotNotation::expandWildcard($array, 'tags.*'))
            ->toBe([
                'tags.0',
                'tags.1',
                'tags.2',
            ]);
    });

    it('expands a wildcard followed by a nested field', function () {
        $array = [
            'items' => [
                ['name' => 'First'],
                ['name' => 'Second'],
            ],
        ];

        expect(DotNotation::expandWildcard($array, 'items.*.name'))
            ->toBe([
                'items.0.name',
                'items.1.name',
            ]);
    });

    it('expands multiple wildcards', function () {
        $array = [
            'groups' => [
                [
                    'users' => [
                        ['name' => 'Anna'],
                        ['name' => 'Maria'],
                    ],
                ],
                [
                    'users' => [
                        ['name' => 'John'],
                    ],
                ],
            ],
        ];

        expect(DotNotation::expandWildcard($array, 'groups.*.users.*.name'))
            ->toBe([
                'groups.0.users.0.name',
                'groups.0.users.1.name',
                'groups.1.users.0.name',
            ]);
    });

    it('expands wildcards over associative keys', function () {
        $array = [
            'users' => [
                'admin' => ['name' => 'Anna'],
                'editor' => ['name' => 'Maria'],
            ],
        ];

        expect(DotNotation::expandWildcard($array, 'users.*.name'))
            ->toBe([
                'users.admin.name',
                'users.editor.name',
            ]);
    });

    it('returns an empty array when the wildcard has no elements', function () {
        $array = ['tags' => []];

        expect(DotNotation::expandWildcard($array, 'tags.*'))
            ->toBe([]);
    });

    it('returns an empty array when the wildcard path does not exist', function () {
        expect(DotNotation::expandWildcard([], 'items.*.name'))
            ->toBe([]);
    });

    it('returns an empty array when the wildcard target is not an array', function () {
        $array = ['items' => 'invalid'];

        expect(DotNotation::expandWildcard($array, 'items.*'))
            ->toBe([]);
    });

    it('keeps a path after wildcard expansion even when the field is missing', function () {
        $array = [
            'items' => [
                ['name' => 'Anna'],
                ['email' => 'maria@example.com'],
            ],
        ];

        expect(DotNotation::expandWildcard($array, 'items.*.name'))
            ->toBe([
                'items.0.name',
                'items.1.name',
            ]);
    });

    it('expands a wildcard at the root followed by a nested field', function () {
        $array = [
            'first' => ['name' => 'Anna'],
            'second' => ['name' => 'Maria'],
        ];

        expect(DotNotation::expandWildcard($array, '*.name'))
            ->toBe([
                'first.name',
                'second.name',
            ]);
    });

    it('returns the path unchanged when there is no wildcard', function () {
        $array = ['app' => ['name' => 'Neo']];

        expect(DotNotation::expandWildcard($array, 'app.name'))
            ->toBe(['app.name']);
    });
});
