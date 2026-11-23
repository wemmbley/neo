<?php

declare(strict_types=1);

use Neo\Information\Persistence\Database\Facades\Schema;
use Neo\Information\Persistence\Database\Migrations\Migration;
use Neo\Information\Persistence\Database\Schema\Blueprint;

return new class extends Migration
{
    public function table(): void
    {
        Schema::create('sessions', function (Blueprint $table) {
            $table->string('id', 80)->primary();
            $table->text('payload');
            $table->integer('last_activity')->index();
        });
    }
};
