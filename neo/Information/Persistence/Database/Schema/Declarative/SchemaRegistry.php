<?php

declare(strict_types=1);

namespace Neo\Information\Persistence\Database\Schema\Declarative;

use Neo\Information\Persistence\Database\Exceptions\SchemaConflictException;

/**
 * Составная схема всех вкладов (только для проверки; историей владеет вклад каждого файла).
 * Строгий режим: таблицу создаёт один файл, колонки и индексы не делятся между файлами.
 */
final class SchemaRegistry
{
    /** @var array<string, string> таблица => файл-создатель */
    private array $creators = [];

    /** @var array<string, array<string, string>> таблица => колонка => файл */
    private array $columns = [];

    /** @var array<string, array<string, string>> таблица => индекс => файл */
    private array $indexes = [];

    /** @param array<string, array{tables: array<string, array>}> $contributions ключ файла => вклад */
    public function __construct(array $contributions)
    {
        foreach ($contributions as $key => $contribution) {
            foreach ($contribution['tables'] as $table => $def) {
                if ($def['mode'] === 'create') {
                    if (isset($this->creators[$table])) {
                        throw new SchemaConflictException(
                            "Таблицу [{$table}] создают два файла: {$this->creators[$table]} и {$key}. Создавать таблицу может только один модуль, остальные используют Schema::extend()."
                        );
                    }
                    $this->creators[$table] = $key;
                }

                foreach (array_keys($def['columns']) as $column) {
                    if (isset($this->columns[$table][$column])) {
                        throw new SchemaConflictException(
                            "Колонка [{$table}.{$column}] объявлена и в {$this->columns[$table][$column]}, и в {$key}. Выберите другое имя."
                        );
                    }
                    $this->columns[$table][$column] = $key;
                }

                foreach (array_keys($def['indexes']) as $index) {
                    if (isset($this->indexes[$table][$index])) {
                        throw new SchemaConflictException(
                            "Индекс [{$table}.{$index}] объявлен и в {$this->indexes[$table][$index]}, и в {$key}. Задайте уникальное имя вторым аргументом index()/unique()."
                        );
                    }
                    $this->indexes[$table][$index] = $key;
                }
            }
        }
    }

    public function creator(string $table): ?string
    {
        return $this->creators[$table] ?? null;
    }

    /** @return list<string> */
    public function columnsOf(string $table): array
    {
        return array_keys($this->columns[$table] ?? []);
    }

    /** Файлы, которым принадлежит что-то в таблице. */
    public function contributors(string $table): array
    {
        return array_values(array_unique(array_values($this->columns[$table] ?? [])));
    }
}
