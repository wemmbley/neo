<?php

declare(strict_types=1);

use Neo\Lang\LangProcessor;

beforeEach(function () {
    $this->langPath = sys_get_temp_dir() . '/neo_lang_' . uniqid();

    mkdir($this->langPath, 0777, true);

    $this->createLocale = function (
        string $locale,
        string $file,
        array $data
    ): void {
        $directory = "{$this->langPath}/{$locale}";

        if (!is_dir($directory)) {
            mkdir($directory, 0777, true);
        }

        $content = '<?php return ' . var_export($data, true) . ';';

        file_put_contents("{$directory}/{$file}.php", $content);
    };

    ($this->createLocale)('en', 'lang', [
        'name' => 'Name',
        'greeting' => 'Hello, :name!',
        'auth' => [
            'failed' => 'Authentication failed.',
            'success' => 'Authentication successful.',
        ],
        'welcome' => 'Welcome',
        'user' => ':name has :count messages.',
        'group' => [
            'nested' => [
                'value' => 'Nested value',
            ],
        ],
    ]);

    ($this->createLocale)('ru', 'lang', [
        'name' => 'Имя',
        'greeting' => 'Привет, :name!',
        'auth' => [
            'failed' => 'Ошибка авторизации.',
        ],
        'welcome' => 'Добро пожаловать',
    ]);

    $this->lang = new LangProcessor('ru', 'en');

    $this->lang->addNamespace('*', $this->langPath);
});

afterEach(function () {
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator(
            $this->langPath,
            FilesystemIterator::SKIP_DOTS
        ),
        RecursiveIteratorIterator::CHILD_FIRST
    );

    foreach ($iterator as $item) {
        $item->isDir()
            ? rmdir($item->getPathname())
            : unlink($item->getPathname());
    }

    if (is_dir($this->langPath)) {
        rmdir($this->langPath);
    }
});

describe('Namespace registration', function () {

    it('registers a namespace', function () {
        expect($this->lang->addNamespace('*', $this->langPath))
            ->toBe($this->lang);
    });

    it('supports multiple namespaces', function () {
        $adminPath = $this->langPath . '/admin';

        mkdir($adminPath);

        mkdir("{$adminPath}/en");

        file_put_contents(
            "{$adminPath}/en/lang.php",
            "<?php return ['title' => 'Admin'];"
        );

        $this->lang->addNamespace('admin', $adminPath);

        expect($this->lang->run()->get('admin::lang.title'))
            ->toBe('Admin');
    });

    it('normalizes namespace paths', function () {
        $this->lang->addNamespace('*', $this->langPath . '///');

        expect($this->lang->run()->get('lang.name'))
            ->toBe('Имя');
    });

});

describe('Initialization', function () {

    it('throws when namespace directory does not exist', function () {
        $this->lang->addNamespace('missing', $this->langPath . '/missing');

        expect(fn () => $this->lang->run())
            ->toThrow(RuntimeException::class);
    });

    it('registers global instance after run', function () {
        $this->lang->run();

        expect(LangProcessor::instance())
            ->toBe($this->lang);
    });

    it('throws when global instance has not been initialized', function () {
        $reflection = new ReflectionClass(LangProcessor::class);

        $property = $reflection->getProperty('instance');
        $property->setValue(null, null);

        expect(fn () => LangProcessor::instance())
            ->toThrow(LogicException::class);
    });

    it('returns itself from run', function () {
        expect($this->lang->run())
            ->toBe($this->lang);
    });

});

describe('Translation retrieval', function () {

    beforeEach(function () {
        $this->lang->run();
    });

    it('returns translation from current locale', function () {
        expect($this->lang->get('lang.name'))
            ->toBe('Имя');
    });

    it('returns translation from fallback locale', function () {
        expect($this->lang->get('lang.welcome'))
            ->toBe('Добро пожаловать');

        expect($this->lang->get('lang.group.nested.value'))
            ->toBe('Nested value');
    });

    it('returns key when translation does not exist', function () {
        expect($this->lang->get('lang.unknown'))
            ->toBe('lang.unknown');
    });

    it('returns nested translation', function () {
        expect($this->lang->get('lang.auth.failed'))
            ->toBe('Ошибка авторизации.');
    });

    it('returns entire translation file when item is omitted', function () {
        $result = $this->lang->get('lang');

        expect($result)
            ->toBeArray()
            ->toHaveKey('name')
            ->toHaveKey('auth');
    });

    it('supports explicit namespace', function () {
        $adminPath = $this->langPath . '/admin';

        mkdir($adminPath);
        mkdir("{$adminPath}/ru");

        file_put_contents(
            "{$adminPath}/ru/lang.php",
            "<?php return ['title' => 'Панель'];"
        );

        $this->lang->addNamespace('admin', $adminPath)->run();

        expect($this->lang->get('admin::lang.title'))
            ->toBe('Панель');
    });

    it('returns non-string translation values without replacement', function () {
        expect($this->lang->get('lang.auth'))
            ->toBeArray()
            ->toHaveKey('failed');
    });

});

describe('Replacements', function () {

    beforeEach(function () {
        $this->lang->run();
    });

    it('replaces placeholders', function () {
        expect($this->lang->get('lang.greeting', [
            'name' => 'Anna',
        ]))->toBe('Привет, Anna!');
    });

    it('replaces multiple placeholders', function () {
        expect($this->lang->get('lang.user', [
            'name' => 'Anna',
            'count' => 5,
        ]))->toBe('Anna has 5 messages.');
    });

    it('supports capitalized placeholders', function () {
        ($this->createLocale)('en', 'replacement', [
            'message' => ':name / :Name / :NAME',
        ]);

        expect($this->lang->get('replacement.message', [
            'name' => 'anna',
        ]))->toBe('anna / Anna / ANNA');
    });

    it('does not replace placeholders when replacement array is empty', function () {
        expect($this->lang->get('lang.greeting'))
            ->toBe('Привет, :name!');
    });

    it('supports numeric replacement values', function () {
        ($this->createLocale)('en', 'numbers', [
            'count' => 'Count: :count',
        ]);

        expect($this->lang->get('numbers.count', [
            'count' => 42,
        ]))->toBe('Count: 42');
    });

});

describe('Locale management', function () {

    beforeEach(function () {
        $this->lang->run();
    });

    it('returns current locale', function () {
        expect($this->lang->getLocale())
            ->toBe('ru');
    });

    it('changes current locale', function () {
        expect($this->lang->setLocale('en')->getLocale())
            ->toBe('en');

        expect($this->lang->get('lang.name'))
            ->toBe('Name');
    });

    it('supports explicit locale override', function () {
        expect($this->lang->get('lang.name', [], 'en'))
            ->toBe('Name');
    });

    it('changes fallback locale', function () {
        $this->lang->setFallbackLocale('ru');

        expect($this->lang->get('lang.name', [], 'en'))
            ->toBe('Name');
    });

});

describe('Translation existence', function () {

    beforeEach(function () {
        $this->lang->run();
    });

    it('returns true when translation exists', function () {
        expect($this->lang->has('lang.name'))
            ->toBeTrue();
    });

    it('returns false when translation does not exist', function () {
        expect($this->lang->has('lang.unknown'))
            ->toBeFalse();
    });

    it('checks only current locale without fallback', function () {
        expect($this->lang->has('lang.welcome', 'ru'))
            ->toBeTrue();

        expect($this->lang->has('lang.group.nested.value', 'ru'))
            ->toBeFalse();
    });

    it('checks nested translations', function () {
        expect($this->lang->has('lang.auth.failed'))
            ->toBeTrue();
    });

});

describe('Available locales', function () {

    beforeEach(function () {
        $this->lang->run();
    });

    it('returns locales for namespace', function () {
        expect($this->lang->getLocales('*'))
            ->toContain('ru')
            ->toContain('en');
    });

    it('returns all available locales', function () {
        expect($this->lang->getLocales())
            ->toContain('ru')
            ->toContain('en');
    });

    it('returns empty array for unknown namespace', function () {
        expect($this->lang->getLocales('unknown'))
            ->toBe([]);
    });

});

describe('Lazy loading and cache', function () {

    beforeEach(function () {
        $this->lang->run();
    });

    it('loads translation file only when requested', function () {
        expect($this->lang->get('lang.name'))
            ->toBe('Имя');

        expect($this->lang->get('lang.auth.failed'))
            ->toBe('Ошибка авторизации.');
    });

    it('caches missing translation files', function () {
        expect($this->lang->get('missing.name'))
            ->toBe('missing.name');

        ($this->createLocale)('ru', 'missing', [
            'name' => 'Поздно загруженный перевод',
        ]);

        expect($this->lang->get('missing.name'))
            ->toBe('missing.name');
    });

    it('clears namespace cache when namespace is registered again', function () {
        expect($this->lang->get('lang.name'))
            ->toBe('Имя');

        $newPath = $this->langPath . '/replacement';

        mkdir($newPath);
        mkdir("{$newPath}/ru");

        file_put_contents(
            "{$newPath}/ru/lang.php",
            "<?php return ['name' => 'Новое имя'];"
        );

        $this->lang->addNamespace('*', $newPath);

        expect($this->lang->get('lang.name'))
            ->toBe('Новое имя');
    });

});

describe('Path security', function () {

    beforeEach(function () {
        $this->lang->run();
    });

    it('does not load files using unsafe locale names', function () {
        expect($this->lang->get('lang.name', [], '../ru'))
            ->toBe('Name');
    });

    it('does not load files using unsafe file names', function () {
        expect($this->lang->get('../lang.name'))
            ->toBe('../lang.name');
    });

    it('does not resolve unknown namespaces', function () {
        expect($this->lang->get('unknown::lang.name'))
            ->toBe('unknown::lang.name');
    });

});
