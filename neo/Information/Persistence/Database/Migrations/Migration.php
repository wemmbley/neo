<?php

declare(strict_types=1);

namespace Neo\Information\Persistence\Database\Migrations;

/**
 * Файл миграции возвращает анонимный наследник и выбирает ОДНУ стратегию.
 *
 * Legacy (up/down, батчи, таблица `migrations`):
 *
 *     return new class extends Migration {
 *         public function up(): void { Schema::create(...); }
 *         public function down(): void { Schema::dropIfExists(...); }
 *     };
 *
 * Декларативная (желаемое состояние, Neo сам считает разницу, история в `migrations_commits`):
 *
 *     return new class extends Migration {
 *         public function table(): void
 *         {
 *             Schema::create('posts', fn (Blueprint $t) => $t->id());
 *             Schema::extend('users', fn (Blueprint $t) => $t->string('telegram_id')->nullable());
 *         }
 *     };
 *
 * Если определены оба метода или ни одного, это ошибка.
 */
abstract class Migration
{
    /** Соединение миграции. null: по умолчанию (или database.migrations.connection). */
    protected ?string $connection = null;

    /** Legacy-стратегия. */
    public function up(): void {}

    public function down(): void {}

    /** Декларативная стратегия: описывает желаемое состояние таблиц файла. */
    public function table(): void {}

    public function getConnection(): ?string
    {
        return $this->connection;
    }
}
