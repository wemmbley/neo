<?php

declare(strict_types=1);

use Neo\Information\Persistence\Database\DatabaseManager;
use Neo\Information\Persistence\Database\Exceptions\QueryException;

function retryDeadlock(): QueryException
{
    $pdo = new PDOException('Deadlock found when trying to get lock; try restarting transaction');
    $pdo->errorInfo = ['40001', 1213, 'Deadlock found when trying to get lock'];

    return new QueryException('UPDATE bench_counters SET value = value + 1', [], $pdo);
}

function retryDb(array $extra = []): DatabaseManager
{
    return new DatabaseManager([
        'default' => 'main',
        'connections' => ['main' => ['driver' => 'sqlite', 'database' => ':memory:'] + $extra],
    ]);
}

test('transaction repeats the outer transaction on deadlock and returns the result', function () {
    $c = retryDb()->connection();
    $runs = 0;

    $result = $c->transaction(function () use (&$runs): string {
        if (++$runs < 3) {
            throw retryDeadlock();
        }

        return 'done';
    }, 3);

    expect($result)->toBe('done')->and($runs)->toBe(3)->and($c->transactionLevel())->toBe(0);
});

test('transaction gives up after the last attempt and rethrows the deadlock', function () {
    $c = retryDb()->connection();
    $runs = 0;

    expect(function () use ($c, &$runs): void {
        $c->transaction(function () use (&$runs): void {
            $runs++;

            throw retryDeadlock();
        }, 2);
    })->toThrow(QueryException::class);

    expect($runs)->toBe(2)->and($c->transactionLevel())->toBe(0);
});

test('transaction does not repeat by default or for errors other than deadlock', function () {
    $c = retryDb()->connection();
    $runs = 0;

    expect(function () use ($c, &$runs): void {
        $c->transaction(function () use (&$runs): void {
            $runs++;

            throw retryDeadlock();
        });
    })->toThrow(QueryException::class);

    expect(function () use ($c, &$runs): void {
        $c->transaction(function () use (&$runs): void {
            $runs++;

            throw new RuntimeException('boom');
        }, 5);
    })->toThrow(RuntimeException::class);

    expect($runs)->toBe(2);
});

test('transaction_attempts from the connection config is used when no argument is passed', function () {
    $c = retryDb(['transaction_attempts' => 3])->connection();
    $runs = 0;

    $c->transaction(function () use (&$runs): void {
        if (++$runs < 3) {
            throw retryDeadlock();
        }
    });

    expect($runs)->toBe(3);
});

test('a nested transaction is never repeated on its own', function () {
    $c = retryDb()->connection();
    $inner = 0;

    expect(function () use ($c, &$inner): void {
        $c->transaction(function () use ($c, &$inner): void {
            $c->transaction(function () use (&$inner): void {
                $inner++;

                throw retryDeadlock();
            }, 3);
        });
    })->toThrow(QueryException::class);

    expect($inner)->toBe(1)->and($c->transactionLevel())->toBe(0);
});

test('fitPools does nothing for sqlite', function () {
    expect(retryDb()->fitPools(12))->toBe([]);
});
