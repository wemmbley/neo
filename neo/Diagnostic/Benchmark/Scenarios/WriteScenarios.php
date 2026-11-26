<?php

declare(strict_types=1);

namespace Neo\Diagnostic\Benchmark\Scenarios;

use Neo\Diagnostic\Benchmark\Models\BenchEvent;
use Neo\Diagnostic\Benchmark\Models\BenchPost;
use Neo\Diagnostic\Benchmark\Support\Context;
use Neo\Diagnostic\Benchmark\Support\Scenario;
use Throwable;

/** Запись: вставки, обновления, транзакции и намеренная борьба за одни и те же строки. */
final class WriteScenarios
{
    /** @return list<Scenario> */
    public static function all(): array
    {
        return [
            new Scenario('db.insert', 'db.write', 'Одиночный INSERT в журнал (автокоммит: цена fsync на каждую запись)', static fn (Context $c): int|string => $c->table('bench_events')
                ->insertGetId(['channel' => 'bench', 'payload' => self::payload()])),

            new Scenario('db.insert_orm', 'db.write', 'INSERT через ORM: Model::create()', static fn (): mixed => BenchEvent::create(['channel' => 'bench-orm', 'payload' => self::payload()])),

            new Scenario('db.bulk', 'db.write', 'Пакетный INSERT (?rows=100) одним запросом', static function (Context $c): int {
                $rows = [];

                for ($i = 0, $n = $c->int('rows', 100, 1, 5000); $i < $n; $i++) {
                    $rows[] = ['channel' => 'bulk', 'payload' => self::payload()];
                }

                $c->table('bench_events')->insert($rows);

                return count($rows);
            }),

            new Scenario('db.bulk_tx', 'db.write', 'Те же вставки по одной, но в одной транзакции (?rows=100): сравните с db.bulk и db.insert', static function (Context $c): int {
                $n = $c->int('rows', 100, 1, 5000);
                $c->connection()->transaction(static function () use ($c, $n): void {
                    for ($i = 0; $i < $n; $i++) {
                        $c->table('bench_events')->insert(['channel' => 'bulk_tx', 'payload' => self::payload()]);
                    }
                });

                return $n;
            }),

            new Scenario('db.update', 'db.write', 'UPDATE случайной строки по PK (views + 1)', static fn (Context $c): int => $c->table('bench_posts')
                ->where('id', '=', $c->postId())->increment('views')),

            new Scenario('db.update_orm', 'db.write', 'SELECT + UPDATE через ORM: find, изменить, save', static function (Context $c): bool {
                $post = BenchPost::find($c->postId());

                if ($post === null) {
                    return false;
                }

                $post->score = $post->score + 1;

                return $post->save();
            }),

            new Scenario('db.counter', 'db.write', 'Горячая строка: все запросы увеличивают один счётчик. ?shards=16 размазывает нагрузку', static function (Context $c): int {
                $shards = $c->int('shards', 1, 1, 16);
                $name = $shards === 1 ? 'hot' : 'shard_' . random_int(0, $shards - 1);

                return $c->table('bench_counters')->where('name', '=', $name)->increment('value');
            }),

            new Scenario('db.transaction', 'db.write', 'Транзакция из 3 записей: комментарий, счётчик поста, событие', static function (Context $c): bool {
                $postId = $c->postId();
                $userId = $c->userId();

                $c->connection()->transaction(static function () use ($c, $postId, $userId): void {
                    $c->table('bench_comments')->insert([
                        'post_id' => $postId, 'user_id' => $userId, 'body' => 'bench comment',
                        'created_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s'),
                    ]);
                    $c->table('bench_posts')->where('id', '=', $postId)->increment('score');
                    $c->table('bench_events')->insert(['channel' => 'comment', 'payload' => self::payload()]);
                });

                return true;
            }),

            new Scenario('db.transfer', 'db.write', 'Перевод между двумя пользователями в случайном порядке блокировок: провоцирует дедлоки, считает повторы (?users=20 сужает пул)', static function (Context $c): array {
                $span = $c->int('users', 20, 2, 100000);
                $base = $c->userId();
                $a = $base;
                $b = $base + random_int(1, $span - 1);

                # Порядок блокировок случайный: без этого строки всегда берутся по возрастанию id и дедлоков не бывает.
                if (random_int(0, 1) === 1) {
                    [$a, $b] = [$b, $a];
                }

                $retries = 0;

                for ($attempt = 0; $attempt < 5; $attempt++) {
                    try {
                        $c->connection()->transaction(static function () use ($c, $a, $b): void {
                            $c->table('bench_users')->where('id', '=', $a)->increment('balance', -1);
                            $c->table('bench_users')->where('id', '=', $b)->increment('balance', 1);
                        });

                        return ['retries' => $retries];
                    } catch (Throwable $e) {
                        if (!self::isContention($e)) {
                            throw $e;
                        }

                        $retries++;
                        usleep(random_int(500, 3000));
                    }
                }

                throw new \RuntimeException("db.transfer: не удалось выполнить за 5 попыток (дедлоки или блокировки), повторов {$retries}");
            }),

            new Scenario('db.delete', 'db.write', 'INSERT + DELETE одной строки', static function (Context $c): int {
                $id = $c->table('bench_events')->insertGetId(['channel' => 'tmp', 'payload' => self::payload()]);

                return $c->table('bench_events')->where('id', '=', $id)->delete();
            }),
        ];
    }

    private static function payload(): string
    {
        return json_encode(['t' => microtime(true), 'pid' => getmypid(), 'r' => random_int(0, 1_000_000)], JSON_THROW_ON_ERROR);
    }

    /** Дедлок, таймаут блокировки или «database is locked» (sqlite). */
    private static function isContention(Throwable $e): bool
    {
        $text = strtolower($e->getMessage() . ' ' . ($e->getPrevious()?->getMessage() ?? ''));

        foreach (['deadlock', 'lock wait timeout', 'database is locked', '1213', '1205', '40001'] as $needle) {
            if (str_contains($text, $needle)) {
                return true;
            }
        }

        return false;
    }
}
