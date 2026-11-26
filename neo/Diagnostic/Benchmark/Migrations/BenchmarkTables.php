<?php

declare(strict_types=1);

use Neo\Information\Persistence\Database\Facades\Schema;
use Neo\Information\Persistence\Database\Migrations\Migration;
use Neo\Information\Persistence\Database\Schema\Blueprint;

# Таблицы для нагрузочных тестов (Diagnostic/Benchmark). Внешних ключей нет намеренно:
# нужна честная стоимость записи и индексов, а не проверок целостности.
return new class extends Migration
{
    public function table(): void
    {
        Schema::create('bench_users', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100);
            $table->string('email')->unique();
            $table->string('status', 16)->default('active')->index();
            $table->bigInteger('balance')->default(0);
            $table->timestamps();
        });

        Schema::create('bench_posts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->index();
            $table->string('title', 200);
            $table->text('body');
            $table->string('status', 16)->default('draft');
            $table->integer('views')->default(0);
            $table->integer('score')->default(0)->index();
            $table->timestamp('published_at')->nullable();
            $table->timestamps();
            $table->index(['status', 'published_at']);
        });

        Schema::create('bench_comments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('post_id');
            $table->foreignId('user_id')->index();
            $table->string('body', 500);
            $table->timestamps();
            $table->index(['post_id', 'created_at']);
        });

        # Горячие строки: все воркеры бьют в одну и ту же запись (блокировки, очередь на row lock).
        Schema::create('bench_counters', function (Blueprint $table) {
            $table->id();
            $table->string('name', 64)->unique();
            $table->bigInteger('value')->default(0);
        });

        # Журнал только на вставку: пропускная способность записи без чтения.
        Schema::create('bench_events', function (Blueprint $table) {
            $table->id();
            $table->string('channel', 32)->index();
            $table->json('payload');
            $table->timestamp('created_at')->useCurrent();
        });
    }
};
