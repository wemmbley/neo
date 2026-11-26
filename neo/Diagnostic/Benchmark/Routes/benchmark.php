<?php

declare(strict_types=1);

use Neo\Diagnostic\Benchmark\Http\BenchmarkController;
use Neo\Network\Http\Facades\Route;

# /benchmark/*      без middleware: «голый» сервер (роутер + контроллер + БД), потолок производительности.
# /benchmark/web/*  через группу web (куки, сессия, CSRF): стоимость полного стека.
$routes = static function (): void {
    Route::get('/', [BenchmarkController::class, 'index']);
    Route::get('/stats', [BenchmarkController::class, 'stats']);
    Route::get('/seed', [BenchmarkController::class, 'seed']);
    Route::get('/reset', [BenchmarkController::class, 'reset']);
    Route::get('/all', [BenchmarkController::class, 'all']);
    # В именах сценариев есть точка (db.pk), а по умолчанию параметр её не принимает.
    Route::get('/{scenario}', [BenchmarkController::class, 'run'])->where('scenario', '[A-Za-z0-9_.\-]+');
};

Route::group(['prefix' => 'benchmark/web', 'middleware' => ['web']], $routes);
Route::group(['prefix' => 'benchmark'], $routes);
