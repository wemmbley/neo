<?php

declare(strict_types=1);

use Neo\Foundation\Config\Config;
use Neo\Foundation\Kernel\Kernel;
use Neo\Foundation\Kernel\ServiceContainer;
use Neo\Information\Lang\LangProcessor;
use Neo\Information\Lang\LangServiceProvider;

beforeEach(function () {
    # new Kernel(...) ниже подменяет глобальное приложение. Запоминаем прежнее, чтобы вернуть его после теста:
    # иначе все следующие тесты (Validator, ResourceAPI, Security) остаются без приложения.
    $this->previousKernel = Kernel::peekInstance();

    $this->langPath = sys_get_temp_dir() . '/neo_lang_' . uniqid();

    $this->packagePath = $this->langPath . '/packages/billing';

    mkdir($this->langPath, 0777, true);

    $this->createLocale = function (
        string $path,
        string $locale,
        string $file,
        array $data
    ): void {
        # Конвенция проекта: папка локали и файл с заглавной буквы (Ru/Lang.php), как у Validator и Faker.
        # LangProcessor ищет именно так, а на Linux регистр в именах файлов важен.
        $directory = "{$path}/" . ucfirst(strtolower($locale));

        if (!is_dir($directory)) {
            mkdir($directory, 0777, true);
        }

        file_put_contents(
            "{$directory}/" . ucfirst($file) . '.php',
            '<?php return ' . var_export($data, true) . ';'
        );
    };

    // Application translations.

    ($this->createLocale)($this->langPath, 'en', 'lang', [
        'name' => 'Name',
        'greeting' => 'Hello, :name!',
        'welcome' => 'Welcome',
        'auth' => [
            'failed' => 'Authentication failed.',
            'success' => 'Authentication successful.',
        ],
        'user' => ':name has :count messages.',
        'group' => [
            'nested' => [
                'value' => 'Nested value',
            ],
        ],
    ]);

    ($this->createLocale)($this->langPath, 'ru', 'lang', [
        'name' => 'Имя',
        'greeting' => 'Привет, :name!',
        'welcome' => 'Добро пожаловать',
        'auth' => [
            'failed' => 'Ошибка авторизации.',
        ],
    ]);

    // Package translations.

    ($this->createLocale)($this->packagePath, 'en', 'lang', [
        'title' => 'Billing',
    ]);

    ($this->createLocale)($this->packagePath, 'ru', 'lang', [
        'title' => 'Биллинг',
    ]);

    // Kernel.

    $this->container = new ServiceContainer();

    $this->kernel = new Kernel(
        $this->container,
        $this->langPath,
        [
            LangServiceProvider::class,
        ]
    );

    // Configuration.

    $this->container->instance(
        Config::class,
        new Config([
            'lang' => [
                'locale' => 'ru',
                'fallback' => 'en',
                'path' => $this->langPath,
            ],
        ])
    );

    // Package resources must be registered before resolving LangProcessor.

    $this->kernel->addResource('translations', [
        'namespace' => 'billing',
        'path' => $this->packagePath,
    ]);

    // Resolve the deferred service.

    $this->lang = $this->kernel->make(LangProcessor::class);
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

    Kernel::setInstance($this->previousKernel);
});

describe('Kernel integration', function () {

    it('registers LangServiceProvider as deferred provider', function () {
        expect($this->kernel->getProviders())
            ->toContain(LangServiceProvider::class);
    });

    it('does not activate deferred provider before service resolution', function () {
        // The provider is activated when LangProcessor is first resolved.
        // This assertion is covered by checking its active state
        // before resolving in a separate kernel.
        $container = new ServiceContainer();

        $kernel = new Kernel(
            $container,
            $this->langPath,
            [LangServiceProvider::class]
        );

        expect($kernel->getActiveProviders())
            ->toBe([]);
    });

    it('activates provider when LangProcessor is resolved', function () {
        expect($this->kernel->getActiveProviders())
            ->toHaveCount(1)
            ->and($this->kernel->getActiveProviders()[0])
            ->toBeInstanceOf(LangServiceProvider::class);
    });

    it('resolves LangProcessor through Kernel', function () {
        expect($this->kernel->make(LangProcessor::class))
            ->toBeInstanceOf(LangProcessor::class);
    });

    it('registers LangProcessor as singleton', function () {
        expect($this->kernel->make(LangProcessor::class))
            ->toBe($this->lang);
    });

    it('provides LangProcessor through deferred provider declaration', function () {
        expect(LangServiceProvider::provides())
            ->toBe([LangProcessor::class]);
    });

    it('uses Kernel singleton instance', function () {
        expect(Kernel::getInstance())
            ->toBe($this->kernel);
    });

    it('uses locale from configuration', function () {
        expect($this->lang->getLocale())
            ->toBe('ru');
    });

});

describe('Namespace registration', function () {

    it('registers a namespace', function () {
        expect(
            $this->lang->addNamespace('admin', $this->langPath)
        )->toBe($this->lang);
    });

    it('supports multiple namespaces', function () {
        $adminPath = $this->langPath . '/admin';

        ($this->createLocale)($adminPath, 'en', 'lang', [
            'title' => 'Admin',
        ]);

        $this->lang->addNamespace('admin', $adminPath);

        expect($this->lang->get('admin::lang.title'))
            ->toBe('Admin');
    });

    it('normalizes namespace paths', function () {
        $this->lang->addNamespace(
            '*',
            $this->langPath . '///'
        );

        expect($this->lang->get('lang.name'))
            ->toBe('Имя');
    });

    it('loads package translations registered through Kernel resources', function () {
        expect($this->lang->get('billing::lang.title'))
            ->toBe('Биллинг');
    });

    it('loads package translations using fallback locale', function () {
        $this->lang->setLocale('fr');

        expect($this->lang->get('billing::lang.title'))
            ->toBe('Billing');
    });

    it('loads application translations under default namespace', function () {
        expect($this->lang->get('lang.name'))
            ->toBe('Имя');
    });

});

describe('Translation retrieval', function () {

    it('returns translation from current locale', function () {
        expect($this->lang->get('lang.name'))
            ->toBe('Имя');
    });

    it('returns translation from fallback locale', function () {
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

        ($this->createLocale)($adminPath, 'ru', 'lang', [
            'title' => 'Панель',
        ]);

        $this->lang->addNamespace('admin', $adminPath);

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
        ($this->createLocale)($this->langPath, 'en', 'replacement', [
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
        ($this->createLocale)($this->langPath, 'en', 'numbers', [
            'count' => 'Count: :count',
        ]);

        expect($this->lang->get('numbers.count', [
            'count' => 42,
        ]))->toBe('Count: 42');
    });

});

describe('Locale management', function () {

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

    it('loads translation files on request', function () {
        expect($this->lang->get('lang.name'))
            ->toBe('Имя');

        expect($this->lang->get('lang.auth.failed'))
            ->toBe('Ошибка авторизации.');
    });

    it('caches missing translation files', function () {
        expect($this->lang->get('missing.name'))
            ->toBe('missing.name');

        ($this->createLocale)($this->langPath, 'ru', 'missing', [
            'name' => 'Поздно загруженный перевод',
        ]);

        expect($this->lang->get('missing.name'))
            ->toBe('missing.name');
    });

    it('clears namespace cache when namespace is registered again', function () {
        expect($this->lang->get('lang.name'))
            ->toBe('Имя');

        $newPath = $this->langPath . '/replacement';

        ($this->createLocale)($newPath, 'ru', 'lang', [
            'name' => 'Новое имя',
        ]);

        $this->lang->addNamespace('*', $newPath);

        expect($this->lang->get('lang.name'))
            ->toBe('Новое имя');
    });

});

describe('Path security', function () {

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
