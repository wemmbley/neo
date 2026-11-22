<?php

declare(strict_types=1);

namespace Neo\Information\Persistence\Database\Migrations;

/**
 * Файл миграции возвращает анонимный наследник:
 *
 *     return new class extends Migration {
 *         public function up(): void { Schema::create(...); }
 *         public function down(): void { Schema::dropIfExists(...); }
 *     };
 */
abstract class Migration
{
    /** Соединение миграции. null: по умолчанию (или database.migrations.connection). */
    protected ?string $connection = null;

    abstract public function up(): void;

    public function down(): void {}

    public function getConnection(): ?string
    {
        return $this->connection;
    }
}
