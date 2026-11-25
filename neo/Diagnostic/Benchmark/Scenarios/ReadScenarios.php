<?php

declare(strict_types=1);

namespace Neo\Diagnostic\Benchmark\Scenarios;

use Neo\Diagnostic\Benchmark\Models\BenchPost;
use Neo\Diagnostic\Benchmark\Models\BenchUser;
use Neo\Diagnostic\Benchmark\Support\Context;
use Neo\Diagnostic\Benchmark\Support\Scenario;

/** Чтение: от пустого round-trip до сканов таблицы. Каждый сценарий отвечает на свой вопрос о БД. */
final class ReadScenarios
{
    /** @return list<Scenario> */
    public static function all(): array
    {
        return [
            new Scenario('db.select1', 'db.read', 'SELECT 1: чистая задержка до БД и стоимость пула', static fn (Context $c): mixed => $c->connection()->selectOne('SELECT 1 AS one')),

            new Scenario('db.pk', 'db.read', 'Выборка по первичному ключу (Query Builder)', static fn (Context $c): mixed => $c->table('bench_users')->find($c->userId())),

            new Scenario('db.pk_orm', 'db.read', 'То же через ORM: цена гидратации модели', static fn (Context $c): mixed => BenchUser::find($c->userId())),

            new Scenario('db.list', 'db.read', 'Последние 20 опубликованных постов по составному индексу', static fn (Context $c): array => $c->table('bench_posts')
                ->where('status', '=', 'published')->orderByDesc('published_at')->limit(20)->get()),

            new Scenario('db.list_orm', 'db.read', 'Те же 20 постов через ORM: гидратация коллекции', static fn (): mixed => BenchPost::query()
                ->where('status', '=', 'published')->orderByDesc('published_at')->limit(20)->get()),

            new Scenario('db.join', 'db.read', 'JOIN постов с авторами, 50 строк', static fn (Context $c): array => $c->table('bench_posts')
                ->join('bench_users', 'bench_users.id', '=', 'bench_posts.user_id')
                ->select('bench_posts.id', 'bench_posts.title', 'bench_users.name')
                ->where('bench_posts.status', '=', 'published')->orderByDesc('bench_posts.id')->limit(50)->get()),

            new Scenario('db.n_plus_one', 'db.read', 'Классическая ловушка: 1 запрос за постами + 20 за авторами', static function (Context $c): int {
                $posts = $c->table('bench_posts')->where('status', '=', 'published')->orderByDesc('id')->limit(20)->get();

                foreach ($posts as $post) {
                    $c->table('bench_users')->find((int) $post['user_id']);
                }

                return count($posts);
            }),

            new Scenario('db.eager', 'db.read', 'То же без ловушки: 2 запроса (whereIn). Сравните с db.n_plus_one', static function (Context $c): int {
                $posts = $c->table('bench_posts')->where('status', '=', 'published')->orderByDesc('id')->limit(20)->get();
                $ids = array_values(array_unique(array_map(static fn (array $p): int => (int) $p['user_id'], $posts)));

                return count($ids === [] ? [] : $c->table('bench_users')->whereIn('id', $ids)->get());
            }),

            new Scenario('db.comments', 'db.read', 'Комментарии поста по индексу (post_id, created_at)', static fn (Context $c): array => $c->table('bench_comments')
                ->where('post_id', '=', $c->postId())->orderByDesc('created_at')->limit(50)->get()),

            new Scenario('db.aggregate', 'db.read', 'GROUP BY по статусу: COUNT и AVG по всей таблице постов', static fn (Context $c): array => $c->table('bench_posts')
                ->select('status', $c->connection()->raw('COUNT(*) AS c'), $c->connection()->raw('AVG(score) AS a'))->groupBy('status')->get(), true),

            new Scenario('db.count', 'db.read', 'COUNT(*) всей таблицы комментариев', static fn (Context $c): int => $c->table('bench_comments')->count(), true),

            new Scenario('db.like', 'db.read', 'LIKE \'%слово%\' без индекса: полный скан таблицы', static fn (Context $c): array => $c->table('bench_posts')
                ->where('title', 'like', '%' . ['alpha', 'swoole', 'kernel', 'storm'][random_int(0, 3)] . '%')->limit(20)->get(), true),

            new Scenario('db.offset', 'db.read', 'Глубокая пагинация: OFFSET до половины таблицы', static function (Context $c): array {
                $offset = random_int(0, max(0, intdiv($c->maxId('bench_posts'), 2)));

                return $c->table('bench_posts')->orderBy('id')->offset($offset)->limit(20)->get();
            }, true),
        ];
    }
}
