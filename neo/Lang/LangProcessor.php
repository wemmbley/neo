<?php

declare(strict_types=1);

namespace Neo\Lang;

use Neo\Config\DotNotation;

/**
 * Процессор локализации.
 *
 * Ключ имеет вид "namespace::file.path.to.key":
 *   __('myspace::lang.name')       → myspace/ru/lang.php, ключ "name"
 *   __('myspace::lang.auth.failed') → вложенный ключ "auth.failed"
 *   __('lang.name')                → пространство по умолчанию ('*')
 *
 * Файлы переводов возвращают массив: `return ['name' => 'Имя'];`
 * Загружаются лениво, при первом обращении к файлу.
 */
final class LangProcessor
{
    /** Пространство имён для ключей без "::". */
    public const DEFAULT_NAMESPACE = '*';

    /** Глобальный экземпляр, который использует хелпер __(). */
    private static ?self $instance = null;

    /** @var array<string, string> namespace => путь к папке с локалями */
    private array $namespaces = [];

    /** @var array<string, array<string, array<string, array|null>>> кеш: ns → locale → file */
    private array $loaded = [];

    /** @var array<string, list<string>> найденные локали по пространствам */
    private array $locales = [];

    public function __construct(
        private string $locale = 'en',
        private string $fallbackLocale = 'en',
    ) {
    }

    /**
     * Регистрирует пространство имён и папку, где лежат подпапки ru, en и т.д.
     */
    public function addNamespace(string $namespace, string $path): static
    {
        $this->namespaces[$namespace] = rtrim($path, '/\\');
        unset($this->loaded[$namespace], $this->locales[$namespace]);

        return $this;
    }

    /**
     * Проверяет папки, находит доступные локали и делает процессор глобальным.
     *
     * @throws \RuntimeException если папка пространства не существует
     */
    public function run(): static
    {
        foreach ($this->namespaces as $namespace => $path) {
            if (!is_dir($path)) {
                throw new \RuntimeException("Lang: папка пространства '{$namespace}' не найдена: {$path}");
            }

            $dirs = glob($path . '/*', GLOB_ONLYDIR) ?: [];
            $this->locales[$namespace] = array_map('basename', $dirs);
        }

        self::$instance = $this;

        return $this;
    }

    /**
     * Глобальный экземпляр (после run()).
     *
     * @throws \LogicException если run() ещё не вызывался
     */
    public static function instance(): self
    {
        return self::$instance
            ?? throw new \LogicException('Lang: сначала вызовите LangProcessor::run().');
    }

    /**
     * Возвращает перевод. Если не найден ни в текущей, ни в запасной локали,
     * возвращается сам ключ. Если ключ указывает на группу, вернётся массив.
     *
     * @param array<string, scalar> $replace подстановки: ['name' => 'Анна'] для ":name"
     */
    public function get(string $key, array $replace = [], ?string $locale = null): mixed
    {
        [$namespace, $file, $item] = $this->parseKey($key);

        $locales = array_unique([$locale ?? $this->locale, $this->fallbackLocale]);

        foreach ($locales as $candidate) {
            $lines = $this->load($namespace, $candidate, $file);

            if ($lines === null || !DotNotation::has($lines, $item)) {
                continue;
            }

            $value = DotNotation::get($lines, $item);

            return is_string($value) ? $this->makeReplacements($value, $replace) : $value;
        }

        return $key;
    }

    /**
     * Есть ли перевод для ключа в указанной (или текущей) локали, без учёта запасной.
     */
    public function has(string $key, ?string $locale = null): bool
    {
        [$namespace, $file, $item] = $this->parseKey($key);
        $lines = $this->load($namespace, $locale ?? $this->locale, $file);

        return $lines !== null && DotNotation::has($lines, $item);
    }

    public function setLocale(string $locale): static
    {
        $this->locale = $locale;

        return $this;
    }

    public function getLocale(): string
    {
        return $this->locale;
    }

    public function setFallbackLocale(string $locale): static
    {
        $this->fallbackLocale = $locale;

        return $this;
    }

    /**
     * Список найденных локалий (по всем пространствам или по одному).
     *
     * @return list<string>
     */
    public function getLocales(?string $namespace = null): array
    {
        if ($namespace !== null) {
            return $this->locales[$namespace] ?? [];
        }

        return array_values(array_unique(array_merge(...array_values($this->locales) ?: [[]])));
    }

    /**
     * Разбирает "ns::file.a.b" на [namespace, file, 'a.b'].
     * Для ключа без точки (только файл) item будет пустой строкой, то есть весь файл.
     *
     * @return array{0: string, 1: string, 2: string}
     */
    private function parseKey(string $key): array
    {
        [$namespace, $rest] = str_contains($key, '::')
            ? explode('::', $key, 2)
            : [self::DEFAULT_NAMESPACE, $key];

        [$file, $item] = array_pad(explode('.', $rest, 2), 2, '');

        return [$namespace, $file, $item];
    }

    /**
     * Лениво загружает файл перевода. null, если файла нет.
     *
     * @return array<string, mixed>|null
     */
    private function load(string $namespace, string $locale, string $file): ?array
    {
        if (array_key_exists($file, $this->loaded[$namespace][$locale] ?? [])) {
            return $this->loaded[$namespace][$locale][$file];
        }

        $lines = null;

        // Имена из ключа попадают в путь: пропускаем только безопасные символы.
        $safe = preg_match('/^[\w-]+$/', $locale) && preg_match('/^[\w-]+$/', $file);

        if ($safe && isset($this->namespaces[$namespace])) {
            $path = "{$this->namespaces[$namespace]}/{$locale}/{$file}.php";

            if (is_file($path)) {
                $data = require $path;
                $lines = is_array($data) ? $data : null;
            }
        }

        return $this->loaded[$namespace][$locale][$file] = $lines;
    }

    /**
     * Подставляет значения: ":name" → как есть, ":Name" → с заглавной, ":NAME" → в верхнем регистре.
     * strtr сам выбирает самое длинное совпадение, поэтому ":names" не сломается из-за ":name".
     */
    private function makeReplacements(string $line, array $replace): string
    {
        if ($replace === []) {
            return $line;
        }

        $map = [];
        foreach ($replace as $name => $value) {
            $value = (string) $value;
            $map[':' . $name] = $value;
            $map[':' . ucfirst((string) $name)] = ucfirst($value);
            $map[':' . strtoupper((string) $name)] = strtoupper($value);
        }

        return strtr($line, $map);
    }
}