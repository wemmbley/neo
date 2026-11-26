<?php

declare(strict_types=1);

use Neo\Diagnostic\Benchmark\Scenarios\Registry;
use Neo\Diagnostic\Benchmark\Support\Stats;

test('Stats считает перцентили по отсортированной выборке', function () {
    $s = Stats::summarize(range(1, 100));

    expect($s['min'])->toBe(1.0)
        ->and($s['max'])->toBe(100.0)
        ->and($s['p50'])->toBe(50.0)
        ->and($s['p95'])->toBe(95.0)
        ->and($s['p99'])->toBe(99.0);
});

test('Stats не падает на пустой выборке', function () {
    expect(Stats::summarize([])['avg'])->toBe(0.0);
});

test('Реестр содержит основные сценарии и группы', function () {
    $registry = new Registry();

    foreach (['ping', 'sleep', 'view', 'db.pk', 'db.n_plus_one', 'db.counter', 'db.transfer', 'mix', 'heavy'] as $name) {
        expect($registry->get($name))->not->toBeNull();
    }

    expect($registry->get('nope'))->toBeNull()
        ->and(count($registry->select(['db.write'])))->toBeGreaterThan(5)
        ->and(array_map(static fn ($s) => $s->name, $registry->select([], ['ping'])))->toBe(['ping']);
});
