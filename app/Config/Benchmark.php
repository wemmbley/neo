<?php

declare(strict_types=1);

return [

    #
    # Слой Diagnostic/Benchmark: маршруты /benchmark/*, таблицы bench_* и команды benchmark:*.
    # По умолчанию включён только в local и testing: маршруты пишут в БД и грузят сервер.
    #
    'enabled' => filter_var(
        env('BENCHMARK_ENABLED', in_array((string) env('APP_ENV', 'production'), ['local', 'testing'], true)),
        FILTER_VALIDATE_BOOL,
    ),

    # Потолки параметров из URL, чтобы один запрос не положил сервер случайно.
    'max_iterations' => (int) env('BENCHMARK_MAX_ITERATIONS', 2000),
    'max_bulk_rows' => (int) env('BENCHMARK_MAX_BULK_ROWS', 5000),
    'max_sleep_ms' => (int) env('BENCHMARK_MAX_SLEEP_MS', 1000),

    # Сколько секунд кэшировать границы id (min/max) в процессе воркера.
    'bounds_ttl' => (int) env('BENCHMARK_BOUNDS_TTL', 5),
];
