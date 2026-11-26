# Diagnostic / Benchmark: нагрузочные тесты

Слой для краш-тестов: маршруты `/benchmark/*`, таблицы `bench_*` (миграция и модели внутри слоя),
команды `php cli benchmark:seed` и `php cli benchmark:run`. Подключён провайдером
`Neo\Diagnostic\Benchmark\BenchmarkServiceProvider` в `app/Config/App.php`.

Включается настройкой `BENCHMARK_ENABLED` (по умолчанию только при `APP_ENV=local|testing`).
Маршруты пишут в БД и грузят сервер, на боевом окружении их быть не должно.

## Быстрый старт

```bash
php cli migrate                      # создаст bench_users, bench_posts, bench_comments, bench_counters, bench_events
php cli benchmark:seed --users=10000 --posts=100000 --comments=500000
php cli benchmark:run --n=200        # замер в консоли, без HTTP
php cli serve --swoole
curl http://127.0.0.1:8000/benchmark          # список сценариев
curl http://127.0.0.1:8000/benchmark/stats    # окружение, пул соединений, размеры таблиц
```

## Маршруты

| Маршрут                     | Что делает                                                                           |
|-----------------------------|--------------------------------------------------------------------------------------|
| `/benchmark`                | Список сценариев                                                                     |
| `/benchmark/{сценарий}`     | Один прогон. `?n=10` повторить, `?result=1` вернуть результат, остальное параметры   |
| `/benchmark/all`            | Пакетный прогон внутри процесса: `?n=50&group=db.read,db.write&only=...&format=text` |
| `/benchmark/seed`           | Наполнение: `?users=1000&posts=10000&comments=50000` (добавляет к существующему)     |
| `/benchmark/reset`          | Очистка всех `bench_*` таблиц                                                        |
| `/benchmark/raw`            | Текст `pong` без раннера и JSON: потолок сервера, роутера и middleware               |
| `/benchmark/stats`          | PHP, Swoole, opcache, пул БД, версия БД, число строк                                 |
| `/benchmark/web/...`        | То же, но через группу `web` (куки, сессия, CSRF): стоимость полного стека           |

Ответ сценария содержит `ms`, число SQL-запросов, время в БД, число повторов и `pid` воркера;
заголовок `Server-Timing` показывает то же в инструментах браузера.

## Сценарии

| Группа     | Сценарии                                                                                                  |
|------------|-----------------------------------------------------------------------------------------------------------|
| `plain`    | `ping`, `cpu`, `json`, `sleep` (`?ms=`), `file`, `view` (Blade)                                           |
| `db.read`  | `db.select1`, `db.pk`, `db.pk_orm`, `db.list`, `db.list_orm`, `db.join`, `db.n_plus_one`, `db.eager`, `db.comments`, `db.aggregate`, `db.count`, `db.like`, `db.offset` |
| `db.write` | `db.insert`, `db.insert_orm`, `db.bulk`, `db.bulk_tx`, `db.update`, `db.update_orm`, `db.counter` (`?shards=`), `db.transaction`, `db.transfer` (`?users=`), `db.delete` |
| `mixed`    | `mix` (80% чтение, 20% запись), `heavy` (aggregate + like + offset + count)                               |

## Как искать просадки

1. **Потолок сервера.** `wrk ... /benchmark/ping`. Всё, что медленнее, добавляют БД или код.
2. **Цена round-trip.** `db.select1`: чистая задержка до БД. Если уже она велика, дело в соединении или пуле.
3. **Стоимость ORM.** `db.pk` против `db.pk_orm`, `db.list` против `db.list_orm`.
4. **Ловушка N+1.** `db.n_plus_one` против `db.eager`: во сколько раз дороже 21 запрос, чем 2.
5. **Запись.** `db.insert` (каждая запись с fsync) против `db.bulk_tx` и `db.bulk`: во что упирается запись.
6. **Блокировки.** `db.counter` (одна горячая строка) против `db.counter?shards=16`; `db.transfer?users=4` провоцирует
   дедлоки. Повторы видны в поле `retries`, неудачи в `Non-2xx responses` у wrk.
7. **Конкурентность.** `sleep?ms=50` при `-c100`: в SAPI RPS упрётся в число процессов, в Swoole будет около `100 / 0.05`.
8. **Рост данных.** Повторите `benchmark/all` после `seed` на разных объёмах: сканы (`db.like`, `db.offset`,
   `db.aggregate`, `db.count`) деградируют линейно, запросы по индексу почти нет.
9. **Полный стек.** `/benchmark/web/mix` против `/benchmark/mix`: разница это куки, сессия и CSRF.

`/benchmark/all` меряет без HTTP, а `wrk` с HTTP. Разница между ними это стоимость сервера, роутера и middleware.
Колонка `db %` показывает, какую долю времени сценария занимает SQL.

## Нагрузка wrk

```bash
wrk -t4 -c100 -d30s --latency http://127.0.0.1:8000/benchmark/mix
wrk -t4 -c100 -d30s --latency -s neo/Diagnostic/Benchmark/wrk/mix.lua http://127.0.0.1:8000
BENCH_PREFIX=/benchmark/web/ wrk -t4 -c100 -d30s --latency -s neo/Diagnostic/Benchmark/wrk/mix.lua http://127.0.0.1:8000
```

Для сравнения SAPI и Swoole подставьте адрес своего nginx-хоста вместо `127.0.0.1:8000`. Для MySQL задайте в `.env`
`DB_CONNECTION=mysql`, параметры подключения и размер пула `DB_POOL_MAX` не меньше числа соединений, которое вы
рассчитываете держать на воркер.

## Параметры

`BENCHMARK_ENABLED`, `BENCHMARK_MAX_ITERATIONS` (потолок `?n=`), `BENCHMARK_MAX_BULK_ROWS`, `BENCHMARK_MAX_SLEEP_MS`,
`BENCHMARK_BOUNDS_TTL` (секунд кэшировать min/max id в процессе).

## Настройка MySQL для честных замеров

- `DB_EMULATE_PREPARES=true` (по умолчанию): параметры подставляет клиент, запрос уходит одним кругом до сервера.
  На серверных prepared statements (`false`) каждый запрос это 2-3 круга, и `db.select1`/`db.pk` в 2 раза медленнее.
- `innodb_flush_log_at_trx_commit=1` (по умолчанию) означает fsync на каждый коммит: `db.insert` упирается в диск
  (около 2 мс на SSD без write cache). Для замеров «потолка» без диска попробуйте значение `2`, но боевые настройки не меняйте.
- `DB_POOL_MAX` не меньше числа одновременных запросов на воркер, иначе запросы ждут свободное соединение.
- `/benchmark/db.transfer` берёт строки в случайном порядке и вызывает настоящие дедлоки InnoDB; они ловятся и повторяются
  (поле `retries`), а `SHOW GLOBAL STATUS LIKE 'Innodb_deadlocks'` покажет их число на стороне сервера.

## Как читать вывод wrk

- Всегда добавляйте `--latency`: средняя задержка у wrk пересчитывается с поправкой на «скоординированное
  опущение», и редкие зависания по 300-500 мс раздувают её в разы. Реальную картину дают p50/p99.
- Строка `Non-2xx or 3xx responses` означает ошибки сценария или 404. Причина пишется в stderr сервера
  (`[benchmark] сценарий: сообщение`).
- `Socket errors: read N` под Swoole обычно значит, что воркер перезапустился и закрыл соединения
  (`SWOOLE_MAX_REQUEST`), либо уперся `SWOOLE_REQUEST_TIMEOUT`.
- Запускайте `wrk`, MySQL и Swoole на одной машине осторожно: у вас 12 потоков, а воркеры + MySQL + 4 потока wrk
  уже конкурируют за них. Для чистого результата ограничьте `SWOOLE_WORKERS=8` и `wrk -t2`.
