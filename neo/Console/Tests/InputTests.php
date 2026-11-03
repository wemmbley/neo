<?php

declare(strict_types=1);

use Neo\Console\Input;

beforeEach(function () {
    $this->input = new Input(
        'test {name} {path?} {--verbose} {--filter=}'
    );
});

/*
|--------------------------------------------------------------------------
| Signature parsing
|--------------------------------------------------------------------------
*/

describe('Signature parsing', function () {

    it('extracts command name', function () {
        expect($this->input->name)
            ->toBe('test');
    });

    it('extracts required arguments', function () {
        expect($this->input->arguments)
            ->toHaveKey('name');

        expect($this->input->arguments['name'])
            ->toMatchArray([
                'required' => true,
                'array' => false,
                'default' => null,
            ]);
    });

    it('extracts optional arguments', function () {
        expect($this->input->arguments['path'])
            ->toMatchArray([
                'required' => false,
                'array' => false,
                'default' => null,
            ]);
    });

    it('extracts options', function () {
        expect($this->input->options)
            ->toHaveKeys(['verbose', 'filter']);
    });

    it('extracts boolean option', function () {
        expect($this->input->options['verbose'])
            ->toMatchArray([
                'shortcut' => null,
                'value' => false,
                'array' => false,
                'default' => false,
            ]);
    });

    it('extracts value option', function () {
        expect($this->input->options['filter'])
            ->toMatchArray([
                'shortcut' => null,
                'value' => true,
                'array' => false,
                'default' => null,
            ]);
    });

    it('extracts argument descriptions', function () {
        $input = new Input('test {name : User name}');

        expect($input->arguments['name']['description'])
            ->toBe('User name');
    });

    it('extracts option descriptions', function () {
        $input = new Input('test {--filter= : Filter by name}');

        expect($input->options['filter']['description'])
            ->toBe('Filter by name');
    });

    it('extracts argument default value', function () {
        $input = new Input('test {name=world}');

        expect($input->arguments['name'])
            ->toMatchArray([
                'required' => false,
                'default' => 'world',
            ]);
    });

    it('extracts option default value', function () {
        $input = new Input('test {--format=json}');

        expect($input->options['format'])
            ->toMatchArray([
                'value' => true,
                'default' => 'json',
            ]);
    });

    it('extracts array arguments', function () {
        $input = new Input('test {files*}');

        expect($input->arguments['files'])
            ->toMatchArray([
                'required' => true,
                'array' => true,
                'default' => [],
            ]);
    });

    it('extracts optional array arguments', function () {
        $input = new Input('test {files*?}');

        expect($input->arguments['files'])
            ->toMatchArray([
                'required' => false,
                'array' => true,
                'default' => [],
            ]);
    });

    it('extracts array options', function () {
        $input = new Input('test {--tag=*}');

        expect($input->options['tag'])
            ->toMatchArray([
                'value' => true,
                'array' => true,
                'default' => [],
            ]);
    });

    it('extracts option shortcut', function () {
        $input = new Input('test {--Q|quiet}');

        expect($input->options['quiet']['shortcut'])
            ->toBe('Q');
    });

    it('supports signatures without arguments and options', function () {
        $input = new Input('cache:clear');

        expect($input->name)->toBe('cache:clear')
            ->and($input->arguments)->toBe([])
            ->and($input->options)->toBe([]);
    });

});

/*
|--------------------------------------------------------------------------
| Argument binding
|--------------------------------------------------------------------------
*/

describe('Argument binding', function () {

    it('binds required arguments', function () {
        $this->input->bind(['Anna']);

        expect($this->input->argument('name'))
            ->toBe('Anna');
    });

    it('binds optional arguments', function () {
        $this->input->bind(['Anna', 'storage']);

        expect($this->input->argument('path'))
            ->toBe('storage');
    });

    it('uses null for missing optional arguments', function () {
        $this->input->bind(['Anna']);

        expect($this->input->argument('path'))
            ->toBeNull();
    });

    it('uses default argument values', function () {
        $input = new Input('test {name=world}');

        $input->bind([]);

        expect($input->argument('name'))
            ->toBe('world');
    });

    it('binds array arguments', function () {
        $input = new Input('test {files*}');

        $input->bind([
            'one.txt',
            'two.txt',
            'three.txt',
        ]);

        expect($input->argument('files'))
            ->toBe([
                'one.txt',
                'two.txt',
                'three.txt',
            ]);
    });

    it('returns empty array for missing optional array argument', function () {
        $input = new Input('test {files*?}');

        $input->bind([]);

        expect($input->argument('files'))
            ->toBe([]);
    });

    it('throws when required argument is missing', function () {
        expect(fn () => $this->input->bind([]))
            ->toThrow(
                InvalidArgumentException::class,
                'Не хватает аргументов: name.'
            );
    });

    it('throws when multiple required arguments are missing', function () {
        $input = new Input('test {name} {email}');

        expect(fn () => $input->bind([]))
            ->toThrow(
                InvalidArgumentException::class,
                'Не хватает аргументов: name, email.'
            );
    });

    it('throws when extra arguments are provided', function () {
        $input = new Input('test {name}');

        expect(fn () => $input->bind([
            'Anna',
            'extra',
        ]))->toThrow(
            InvalidArgumentException::class,
            'Лишние аргументы: extra.'
        );
    });

    it('returns all argument values', function () {
        $this->input->bind([
            'Anna',
            'storage',
        ]);

        expect($this->input->argument())
            ->toBe([
                'name' => 'Anna',
                'path' => 'storage',
            ]);
    });

});

/*
|--------------------------------------------------------------------------
| Boolean options
|--------------------------------------------------------------------------
*/

describe('Boolean options', function () {

    it('uses false as default value', function () {
        $this->input->bind(['Anna']);

        expect($this->input->option('verbose'))
            ->toBeFalse();
    });

    it('enables boolean option', function () {
        $this->input->bind([
            'Anna',
            '--verbose',
        ]);

        expect($this->input->option('verbose'))
            ->toBeTrue();
    });

    it('supports boolean shortcut', function () {
        $input = new Input('test {--Q|quiet}');

        $input->bind(['-Q']);

        expect($input->option('quiet'))
            ->toBeTrue();
    });

    it('throws when boolean option receives a value', function () {
        expect(fn () => $this->input->bind([
            'Anna',
            '--verbose=yes',
        ]))->toThrow(
            InvalidArgumentException::class,
            'не принимает значение'
        );
    });

});

/*
|--------------------------------------------------------------------------
| Value options
|--------------------------------------------------------------------------
*/

describe('Value options', function () {

    it('binds option using equals syntax', function () {
        $this->input->bind([
            'Anna',
            '--filter=admin',
        ]);

        expect($this->input->option('filter'))
            ->toBe('admin');
    });

    it('binds option using separate value', function () {
        $this->input->bind([
            'Anna',
            '--filter',
            'admin',
        ]);

        expect($this->input->option('filter'))
            ->toBe('admin');
    });

    it('throws when option value is missing', function () {
        expect(fn () => $this->input->bind([
            'Anna',
            '--filter',
        ]))->toThrow(
            InvalidArgumentException::class,
            'нужно значение'
        );
    });

    it('supports shortcut with inline value', function () {
        $input = new Input('test {--F|filter=}');

        $input->bind([
            'Anna',
            '-Fadmin',
        ]);

        expect($input->option('filter'))
            ->toBe('admin');
    });

    it('supports shortcut with equals value', function () {
        $input = new Input('test {--F|filter=}');

        $input->bind([
            'Anna',
            '-F=admin',
        ]);

        expect($input->option('filter'))
            ->toBe('admin');
    });

    it('uses default option value', function () {
        $input = new Input('test {--format=json}');

        $input->bind([]);

        expect($input->option('format'))
            ->toBe('json');
    });

    it('returns null for option without default value', function () {
        $input = new Input('test {--filter=}');

        $input->bind([]);

        expect($input->option('filter'))
            ->toBeNull();
    });

});

/*
|--------------------------------------------------------------------------
| Array options
|--------------------------------------------------------------------------
*/

describe('Array options', function () {

    it('collects multiple option values', function () {
        $input = new Input('test {--tag=*}');

        $input->bind([
            '--tag=php',
            '--tag=neo',
            '--tag=framework',
        ]);

        expect($input->option('tag'))
            ->toBe([
                'php',
                'neo',
                'framework',
            ]);
    });

    it('supports separate values for array options', function () {
        $input = new Input('test {--tag=*}');

        $input->bind([
            '--tag',
            'php',
            '--tag',
            'neo',
        ]);

        expect($input->option('tag'))
            ->toBe(['php', 'neo']);
    });

    it('returns empty array when no array options are provided', function () {
        $input = new Input('test {--tag=*}');

        $input->bind([]);

        expect($input->option('tag'))
            ->toBe([]);
    });

});

/*
|--------------------------------------------------------------------------
| Shortcuts
|--------------------------------------------------------------------------
*/

describe('Shortcuts', function () {

    it('resolves short option names', function () {
        $input = new Input('test {--Q|quiet}');

        $input->bind(['-Q']);

        expect($input->option('quiet'))
            ->toBeTrue();
    });

    it('throws for unknown shortcut', function () {
        $input = new Input('test {--Q|quiet}');

        expect(fn () => $input->bind(['-X']))
            ->toThrow(
                InvalidArgumentException::class,
                'не существует'
            );
    });

    it('throws for unknown long option', function () {
        expect(fn () => $this->input->bind([
            'Anna',
            '--unknown',
        ]))->toThrow(
            InvalidArgumentException::class,
            'не существует'
        );
    });

});

/*
|--------------------------------------------------------------------------
| Argument and option access
|--------------------------------------------------------------------------
*/

describe('Value access', function () {

    it('returns individual argument', function () {
        $this->input->bind(['Anna']);

        expect($this->input->argument('name'))
            ->toBe('Anna');
    });

    it('returns individual option', function () {
        $this->input->bind([
            'Anna',
            '--verbose',
        ]);

        expect($this->input->option('verbose'))
            ->toBeTrue();
    });

    it('throws for undefined argument', function () {
        expect(fn () => $this->input->argument('unknown'))
            ->toThrow(
                InvalidArgumentException::class,
                'Аргумент "unknown" не определён.'
            );
    });

    it('throws for undefined option', function () {
        expect(fn () => $this->input->option('unknown'))
            ->toThrow(
                InvalidArgumentException::class,
                'Опция "unknown" не определён.'
            );
    });

    it('returns all options', function () {
        $this->input->bind([
            'Anna',
            '--verbose',
            '--filter=admin',
        ]);

        expect($this->input->option())
            ->toBe([
                'verbose' => true,
                'filter' => 'admin',
            ]);
    });

});

/*
|--------------------------------------------------------------------------
| End of options separator
|--------------------------------------------------------------------------
*/

describe('End of options separator', function () {

    it('treats everything after double dash as positional arguments', function () {
        $input = new Input('test {name} {path}');

        $input->bind([
            'Anna',
            '--',
            '--verbose',
        ]);

        expect($input->argument('name'))
            ->toBe('Anna');

        expect($input->argument('path'))
            ->toBe('--verbose');
    });

    it('does not parse options after double dash', function () {
        $input = new Input('test {name} {--verbose}');

        $input->bind([
            'Anna',
            '--',
            '--verbose',
        ]);

        expect($input->option('verbose'))
            ->toBeFalse();
    });

    it('preserves option-like values after double dash', function () {
        $input = new Input('test {values*}');

        $input->bind([
            '--',
            '--verbose',
            '-Q',
            '--filter=admin',
        ]);

        expect($input->argument('values'))
            ->toBe([
                '--verbose',
                '-Q',
                '--filter=admin',
            ]);
    });

});

/*
|--------------------------------------------------------------------------
| Usage generation
|--------------------------------------------------------------------------
*/

describe('Usage', function () {

    it('generates usage for command without parameters', function () {
        $input = new Input('cache:clear');

        expect($input->usage())
            ->toBe('cache:clear');
    });

    it('generates usage for required arguments', function () {
        $input = new Input('test {name}');

        expect($input->usage())
            ->toBe('test <name>');
    });

    it('generates usage for optional arguments', function () {
        $input = new Input('test {path?}');

        expect($input->usage())
            ->toBe('test [<path>]');
    });

    it('generates usage for array arguments', function () {
        $input = new Input('test {files*}');

        expect($input->usage())
            ->toBe('test <files>...');
    });

    it('generates usage for boolean options', function () {
        $input = new Input('test {--verbose}');

        expect($input->usage())
            ->toBe('test [--verbose]');
    });

    it('generates usage for value options', function () {
        $input = new Input('test {--filter=}');

        expect($input->usage())
            ->toBe('test [--filter=FILTER]');
    });

    it('generates usage for shortcut options', function () {
        $input = new Input('test {--Q|quiet}');

        expect($input->usage())
            ->toBe('test [-Q|--quiet]');
    });

    it('generates complete usage string', function () {
        $input = new Input(
            'test {path?} {--filter=} {--Q|quiet}'
        );

        expect($input->usage())
            ->toBe('test [--filter=FILTER] [-Q|--quiet] [<path>]');
    });

});

/*
|--------------------------------------------------------------------------
| Rebinding
|--------------------------------------------------------------------------
*/

describe('Rebinding', function () {

    it('resets option values between bindings', function () {
        $this->input->bind([
            'Anna',
            '--verbose',
            '--filter=admin',
        ]);

        $this->input->bind(['John']);

        expect($this->input->option('verbose'))
            ->toBeFalse();

        expect($this->input->option('filter'))
            ->toBeNull();
    });

    it('resets array option values between bindings', function () {
        $input = new Input('test {--tag=*}');

        $input->bind([
            '--tag=php',
            '--tag=neo',
        ]);

        $input->bind([
            '--tag=framework',
        ]);

        expect($input->option('tag'))
            ->toBe(['framework']);
    });

    it('replaces argument values between bindings', function () {
        $this->input->bind(['Anna', 'storage']);

        $this->input->bind(['John']);

        expect($this->input->argument('name'))
            ->toBe('John');

        expect($this->input->argument('path'))
            ->toBeNull();
    });

});
