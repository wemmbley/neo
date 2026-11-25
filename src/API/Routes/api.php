<?php

declare(strict_types=1);

use Neo\Network\Http\Facades\Route;

# API: префикс /api, группа «api» (лимит запросов), без сессии и CSRF.

Route::get('/ping', fn () => ['ok' => true])->name('ping');

# ResourceAPI: REST-ресурс по модели одной строкой.

Route::resource(\Src\API\Http\Resource\UserResourceAPI::class);