# Performance Benchmark Test

В данной сравнительной таблице происходит агрессивный бенчмарк 
на базе данных 500.000 комментариев, 10.000 пользователей и 
100.000 постов.

## Результаты

| Concurrency	 | Neo RPS	  | Laravel RPS | Преимущество Neo |
|---------------|------------|-------------|------------------|
| 10	         | 🏆 `4 641`   | 	2 050	   | **2,26×**            |     
| 25	         | 🏆 `6 874`   | 	2 263	   | **3,04×**            |       
| 50	         | 🏆 `6 862`   | 	2 264	   | **3,03×**            |      
| 100	         | 🏆 `6 858`   | 	2 200	   | **3,12×**            |     
| 200	         | 🏆 `6 682`   | 	1 999	   | **3,34×**            |    
| 400	         | 🏆 `6 658`   | 	2 174	   | **3,06×**            |   

Neo обогнал Ларавель на пропускной способности в 3.1х раз.
2 тысяч РПС не хватило в 10 конкурентности по следующей причине:
Это закон Литтла: пропускная способность равна числу запросов 
в полёте, делённому на задержку.

| Concurrency	| Neo CPU	| Laravel CPU |
|---------------|-----------|-------------|
|10	|42,1%	|79,2%|
|25	|58%	|88,5%|
|50	|57,3%	|89,6%|
|100|	60,9%|	90,3%|
|200|	57,7%|	93%|
|400|	58%	|91,8%|

Neo обеспечивает примерно трёхкратную пропускную способность при существенно меньшей загрузке CPU.

| Показатель	| Neo |	Laravel |
RAM при 10	51,6%	57%
RAM при 100	51,8%	57,1%
RAM при 400	52,1%	57,7%

Оба приложения используют примерно одинаковую долю доступной памяти.

## Описание

Происходит загрузка всего контейнера со всеми зависимостями, 
роутинг и рендеринг небольшого blade-файла шаблонизатором.

Моя машина:

| Имя параметра        | Значение                            |
|----------------------|-------------------------------------|
| CPU(s):              | 12                                  |
| On-line CPU(s) list: | 0-11                                |
| Model name:          | AMD Ryzen 5 2600 Six-Core Processor |
| Thread(s) per core:  | 2                                   |
| Core(s) per socket:  | 6                                   |
| CPU(s) scaling MHz:  | 74%                                 |
| NUMA node0 CPU(s):   | 0-11                                |
| Memory               | 16gb                                |
| Free Memory          | 8gb                                 |

# Neo 4 threads

```bash
wrk -t4 -c100 -d30s --latency http://127.0.0.1:8000/benchmark/mix
```

```
Running 30s test @ http://127.0.0.1:8000/benchmark/mix
  4 threads and 100 connections
  Thread Stats   Avg      Stdev     Max   +/- Stdev
    Latency    10.01ms    3.69ms  85.46ms   92.40%
    Req/Sec     2.55k   250.43     3.07k    70.67%
  Latency Distribution
     50%    9.10ms
     75%   10.34ms
     90%   12.69ms
     99%   25.19ms
  305032 requests in 30.01s, 148.04MB read
Requests/sec:  10164.88
Transfer/sec:      4.93MB
```

## Laravel 4 threads

```bash
wrk -t4 -c100 -d30s --latency http://127.0.0.1:8111/benchmark/mix
```

```
Running 30s test @ http://127.0.0.1:8111/benchmark/mix
  4 threads and 100 connections
  Thread Stats   Avg      Stdev     Max   +/- Stdev
    Latency    52.21ms   65.00ms 565.85ms   92.28%
    Req/Sec   657.00    110.23     0.91k    68.17%
  Latency Distribution
     50%   32.09ms
     75%   44.66ms
     90%   69.60ms
     99%  359.59ms
  78630 requests in 30.09s, 26.61MB read
Requests/sec:   2613.38
Transfer/sec:      0.88MB
```

## Замечания

Для того чтобы тестирование не упиралось в потолок загрузки 
MySQL, были выполнены следующие команды перед стартом:
```
SET GLOBAL innodb_flush_log_at_trx_commit = 2;
SET GLOBAL sync_binlog = 0;
```

Результаты БЕЗ этих значений следующие:
Laravel:
```
Running 30s test @ http://127.0.0.1:8111/benchmark/mix
  4 threads and 100 connections
  Thread Stats   Avg      Stdev     Max   +/- Stdev
    Latency    51.05ms   45.90ms 408.38ms   93.64%
    Req/Sec   578.52     99.10   800.00     65.17%
  Latency Distribution
     50%   39.18ms
     75%   49.70ms
     90%   67.72ms
     99%  282.08ms
  69259 requests in 30.08s, 23.44MB read
Requests/sec:   2302.45
Transfer/sec:    797.93KB
```
Neo:
```
Running 30s test @ http://127.0.0.1:8000/benchmark/mix
  4 threads and 100 connections
  Thread Stats   Avg      Stdev     Max   +/- Stdev
    Latency    15.43ms    7.65ms 107.97ms   82.42%
    Req/Sec     1.68k   340.04     2.47k    63.75%
  Latency Distribution
     50%   13.49ms
     75%   18.23ms
     90%   24.44ms
     99%   44.62ms
  200116 requests in 30.01s, 97.35MB read
Requests/sec:   6667.22
Transfer/sec:      3.24MB
```
Дальше по тестам это не используется. Стоят значения по умолчанию 
MySQL.

## Описание теста

/benchmark/mix представляет собой weighted workload с распределением
нагрузки по реальным сценариям выполнения:

Read-нагрузка:
- Поиск записи по Primary Key через Query Builder.
- Выборка 20 опубликованных постов с сортировкой и индексом.
- JOIN постов с пользователями.
- Выборка 50 комментариев по post_id с сортировкой.
- Поиск записи через Eloquent с гидратацией модели.
  Write-нагрузка:
- INSERT событий.
- UPDATE поста.
- Транзакция, включающая INSERT комментария, UPDATE рейтинга поста и INSERT события.
- Инкремент счётчика с конкуренцией за запись.

Это смесь разных типов SQL-операций, Query Builder, Eloquent,
выборок, JOIN, индексов, гидратации моделей, INSERT, UPDATE
и транзакций.

Параллельно тестируется вся цепочка Laravel 13 + HTTP routing +
middleware + DI/container + обработка запроса + формирование
JSON-ответа + Octane/Swoole runtime.

Тест рассчитан на 10 000 пользователей, 100 000 постов и 500 000
комментариев.

# Neo Benchmark

```
scenario                n     ops/s      avg      p50      p95      p99      max   q/op    db %   err  retr
-----------------------------------------------------------------------------------------------------------
[plain]
ping                  100 5472255.7    0.000    0.000    0.000    0.000    0.001   0.00    0.0%     0     0
cpu                   100     618.0    1.618    1.594    1.733    1.912    1.979   0.00    0.0%     0     0
json                  100    2453.9    0.407    0.402    0.446    0.463    0.492   0.00    0.0%     0     0
sleep                 100      99.2   10.077   10.073   10.107   10.109   10.128   0.00    0.0%     0     0
file                  100    5687.1    0.175    0.167    0.208    0.240    0.327   0.00    0.0%     0     0
view                  100    5272.0    0.189    0.184    0.209    0.246    0.253   0.00    0.0%     0     0
[db.read]
db.select1            100    7522.8    0.133    0.128    0.152    0.163    0.180   1.00   95.9%     0     0
db.pk                 100    3606.6    0.277    0.271    0.335    0.358    0.361   1.00   92.2%     0     0
db.pk_orm             100    2664.5    0.375    0.367    0.449    0.474    0.485   1.00   78.4%     0     0
db.list               100    2870.8    0.348    0.324    0.544    0.565    0.635   1.00   86.1%     0     0
db.list_orm           100    1629.4    0.613    0.466    1.048    1.114    1.173   1.00   50.7%     0     0
db.join               100    2614.1    0.382    0.379    0.446    0.469    0.481   1.00   91.3%     0     0
db.n_plus_one         100     229.7    4.352    3.953    6.884    7.155    7.548  21.00   83.4%     0     0
db.eager              100    1601.2    0.624    0.594    0.863    1.048    1.073   2.00   84.7%     0     0
db.comments           100    1537.8    0.650    0.611    1.096    1.326    1.459   1.00   93.4%     0     0
db.aggregate           10       3.2  314.431  303.485  352.570  352.570  352.570   1.00  100.0%     0     0
db.count               10       8.7  114.606  111.271  124.706  124.706  124.706   1.00   99.9%     0     0
db.like                10    2586.5    0.386    0.358    0.474    0.474    0.474   1.00   83.7%     0     0
db.offset              10      41.1   24.308   24.842   35.330   35.330   35.330   1.00   99.7%     0     0
[db.write]
db.insert             100     442.9    2.257    2.080    3.255    4.524    4.539   1.00   97.6%     0     0
db.insert_orm         100     421.0    2.375    2.382    2.827    3.004    3.355   1.00   95.9%     0     0
db.bulk               100     187.3    5.338    5.298    6.402    7.726   19.517   1.00   90.2%     0     0
db.bulk_tx            100      51.7   19.345   18.148   30.022   34.290   35.714 100.00   72.2%     0     0
db.update             100     419.4    2.383    2.371    2.829    2.920    3.078   1.00   97.8%     0     0
db.update_orm         100     356.1    2.807    2.842    3.385    3.923    3.938   2.00   91.1%     0     0
db.counter            100     484.0    2.065    1.912    2.612    3.481    6.416   1.00   97.8%     0     0
db.transaction        100     286.6    3.488    3.310    4.810    6.312   11.102   3.00   26.5%     0     0
db.transfer           100     359.9    2.778    2.768    3.259    3.671    4.215   2.00   21.3%     0     0
db.delete             100     226.9    4.407    4.411    5.106    5.273    5.389   2.00   98.0%     0     0
[mixed]
mix                   100    1080.4    0.925    0.541    2.436    5.802    6.063   1.03   87.5%     0     0
heavy                  10       3.7  269.477  270.439  282.610  282.610  282.610   4.00   99.9%     0     0
```

При включённом кэше View:
```
view                  100   14668.4    0.068    0.066    0.077    0.089    0.094   0.00    0.0%     0     0
```

## Laravel Benchmark
```
scenario                n     ops/s      avg      p50      p95      p99      max   q/op    db %   err  retr
-----------------------------------------------------------------------------------------------------------
[plain]
ping                  100 6172458.5    0.000    0.000    0.000    0.000    0.000   0.00    0.0%     0     0
cpu                   100     641.9    1.558    1.546    1.602    1.637    1.650   0.00    0.0%     0     0
json                  100    2517.0    0.397    0.396    0.408    0.422    0.449   0.00    0.0%     0     0
sleep                 100   15831.6    0.063    0.065    0.066    0.069    0.071   0.00    0.0%     0     0
file                  100   14296.7    0.070    0.069    0.076    0.081    0.082   0.00    0.0%     0     0
view                  100    4946.4    0.202    0.199    0.217    0.241    0.244   0.00    0.0%     0     0
[db.read]
db.select1            100    5400.3    0.185    0.191    0.233    0.408    0.408   1.00   89.0%     0     0
db.pk                 100    2471.2    0.404    0.413    0.473    0.494    0.534   1.00   89.3%     0     0
db.pk_orm             100    2230.0    0.448    0.349    0.687    0.765    0.852   1.00   68.1%     0     0
db.list               100    1413.5    0.707    0.701    0.982    1.119    1.127   1.00   80.5%     0     0
db.list_orm           100    1056.7    0.946    0.661    1.866    1.880    1.971   1.00   53.0%     0     0
db.join               100    1587.2    0.630    0.603    1.074    1.147    1.160   1.00   79.2%     0     0
db.n_plus_one         100     120.6    8.293    7.966   13.762   13.981   14.039  21.00   79.3%     0     0
db.eager              100     816.0    1.225    1.150    1.784    1.871    2.044   2.00   78.9%     0     0
db.comments           100    1334.8    0.748    0.769    0.967    1.140    1.236   1.00   79.9%     0     0
db.aggregate           10       3.9  259.293  258.898  262.481  262.481  262.481   1.00   99.9%     0     0
db.count               10      15.3   65.159   65.060   65.997   65.997   65.997   1.00   99.7%     0     0
db.like                10    2371.2    0.421    0.405    0.552    0.552    0.552   1.00   85.1%     0     0
db.offset              10      50.2   19.900   18.524   37.133   37.133   37.133   2.00   99.1%     0     0
[db.write]
db.insert             100     409.6    2.441    2.385    3.022    3.273    4.206   1.00   95.3%     0     0
db.insert_orm         100     402.8    2.482    2.424    3.057    3.434    3.488   1.00   90.1%     0     0
db.bulk               100     156.9    6.374    6.684    9.372   10.774   11.237   1.00   87.8%     0     0
db.bulk_tx            100      32.1   31.152   28.351   47.527   57.680   59.977 100.00   72.2%     0     0
db.update             100     418.7    2.388    2.297    3.035    4.258    8.110   1.00   94.7%     0     0
db.update_orm         100     232.0    4.309    4.275    4.942    5.198    5.262   2.00   75.5%     0     0
db.counter            100     411.0    2.432    2.389    3.012    3.279    3.395   1.00   93.5%     0     0
db.transaction        100     223.9    4.466    4.397    5.325    7.904    8.506   3.00   37.3%     0     0
db.transfer           100     263.7    3.791    3.706    4.285    5.514    7.453   2.00   31.0%     0     0
db.delete             100     194.1    5.151    5.006    6.039    7.422    8.161   2.00   95.3%     0     0
[mixed]
mix                   100    1088.6    0.918    0.588    2.532    3.204    3.309   1.04   78.5%     0     0
heavy                  10       4.4  226.129  224.948  253.727  253.727  253.727   5.00   99.7%     0     0
Times in ms; db % is SQL time share.
```

Laravel кэш не отключался, по умолчанию он кэшировал что мог.

## Описание теста

Это CLI-бенчмарк, который запускает сценарии непосредственно внутри 
PHP-процесса, без HTTP-сервера, TCP, HTTP routing.

Он нужен для измерения стоимости непосредственно Laravel, PHP, Query 
Builder, Eloquent, Blade и операций с инфраструктурой.

В отличие от /benchmark/mix, CLI запускает каждый сценарий отдельно 
и показывает его индивидуальную производительность.

1. Plain PHP:
ping — минимальный сценарий для измерения накладных расходов самого Runner.
cpu — 2000 последовательных MD5-хеширований и сортировка 2000 случайных чисел.
json — построение массива из 300 записей, JSON-кодирование и обратная десериализация.
sleep — контролируемая задержка через usleep.
file — запись, чтение и удаление файла размером 4 КБ.
view — полноценный рендеринг Blade-шаблона с 50 строками.

2. Database Read:
db.select1 — SELECT 1.
db.pk — получение записи по Primary Key через Query Builder.
db.pk_orm — получение той же сущности через Eloquent с гидратацией модели.
db.list — выборка 20 опубликованных постов с сортировкой.
db.list_orm — аналогичная выборка через Eloquent.
db.join — JOIN пользователей и постов, 50 результатов.
db.n_plus_one — один запрос постов и ещё 20 отдельных запросов авторов.
db.eager — выборка постов и авторов двумя запросами вместо N+1.
db.comments — выборка 50 комментариев с сортировкой.
db.aggregate — GROUP BY, COUNT и AVG.
db.count — подсчёт всех комментариев.
db.like — поиск по LIKE с шаблоном %слово%.
db.offset — глубокая OFFSET-пагинация.

3. Database Write:
db.insert — INSERT через Query Builder.
db.insert_orm — создание модели через Eloquent.
db.bulk — массовая вставка 100 записей.
db.bulk_tx — 100 отдельных INSERT внутри одной транзакции.
db.update — UPDATE через инкремент счётчика.
db.update_orm — Eloquent find + изменение модели + save.
db.counter — конкурентное обновление горячей строки счётчика.
db.transaction — полноценная транзакция из трёх операций: INSERT комментария, UPDATE поста и INSERT события.
db.transfer — перевод между двумя пользователями с обработкой deadlock и повторными попытками.
db.delete — INSERT и последующее удаление записи.

4. Mixed:
mix — случайное распределение операций чтения и записи по весам 80/20.
heavy — последовательное выполнение четырёх тяжёлых запросов: GROUP BY, LIKE, OFFSET и COUNT.

Как работает измерение:

Для каждого сценария Runner:
Выполняет заданное количество прогревочных итераций (--warmup=5).
Обнуляет счётчики SQL-запросов и времени БД.
Измеряет каждую итерацию через hrtime(true).
Собирает индивидуальные времена выполнения.
Рассчитывает статистику производительности.

Выводятся:
ops/s — операций в секунду.
avg — среднее время.
p50, p95, p99 — перцентили задержки.
max — максимальное время.
q/op — количество SQL-запросов на операцию.
db % — доля времени SQL в общем времени выполнения сценария.
err — ошибки.
retr — повторные попытки транзакций.

Для тяжёлых сценариев количество итераций автоматически уменьшается 
до max(3, n / 10).

## Увеличение ядер и оперативной памяти

### Neo

```
Neo Capacity Analyzer
───────────────────────────────────────────────────────
+--------------------+-------------------------------------+
| Resource           | Value                               |
+--------------------+-------------------------------------+
| Target             | http://127.0.0.1:8000/benchmark/mix |
| CPU cores          | 12                                  |
| Total RAM          | 15.54 GB                            |
| Duration           | 10 seconds per stage                |
| Concurrency levels | 10, 25, 50, 100, 200, 400           |
+--------------------+-------------------------------------+

Testing 10 concurrent connections...
+--------------------+----------+
| Metric             | Result   |
+--------------------+----------+
| Requests/sec       | 4547.91  |
| Requests completed | 45553    |
| Average latency    | 2 ms     |
| P50                | 1.05 ms  |
| P95                | 6.4 ms   |
| P99                | 10.63 ms |
| CPU average        | 37%      |
| CPU peak           | 44%      |
| RAM peak           | 45.1%    |
| Errors             | 0        |
| Error rate         | 0%       |
| Status             | STABLE   |
+--------------------+----------+

Testing 25 concurrent connections...
+--------------------+----------+
| Metric             | Result   |
+--------------------+----------+
| Requests/sec       | 6524.58  |
| Requests completed | 65387    |
| Average latency    | 3.71 ms  |
| P50                | 1.97 ms  |
| P95                | 10.79 ms |
| P99                | 23.44 ms |
| CPU average        | 49.9%    |
| CPU peak           | 65.7%    |
| RAM peak           | 45.2%    |
| Errors             | 0        |
| Error rate         | 0%       |
| Status             | STABLE   |
+--------------------+----------+

Testing 50 concurrent connections...
+--------------------+----------+
| Metric             | Result   |
+--------------------+----------+
| Requests/sec       | 6551.8   |
| Requests completed | 65570    |
| Average latency    | 7.52 ms  |
| P50                | 5.65 ms  |
| P95                | 17.24 ms |
| P99                | 31.91 ms |
| CPU average        | 49.9%    |
| CPU peak           | 54.7%    |
| RAM peak           | 45.2%    |
| Errors             | 0        |
| Error rate         | 0%       |
| Status             | STABLE   |
+--------------------+----------+

Testing 100 concurrent connections...
+--------------------+----------+
| Metric             | Result   |
+--------------------+----------+
| Requests/sec       | 6585.98  |
| Requests completed | 65957    |
| Average latency    | 15.07 ms |
| P50                | 13.33 ms |
| P95                | 27.95 ms |
| P99                | 40.84 ms |
| CPU average        | 50.6%    |
| CPU peak           | 58.7%    |
| RAM peak           | 45.2%    |
| Errors             | 0        |
| Error rate         | 0%       |
| Status             | STABLE   |
+--------------------+----------+

Testing 200 concurrent connections...
+--------------------+----------+
| Metric             | Result   |
+--------------------+----------+
| Requests/sec       | 6479.37  |
| Requests completed | 65140    |
| Average latency    | 30.6 ms  |
| P50                | 28.64 ms |
| P95                | 48.8 ms  |
| P99                | 62.53 ms |
| CPU average        | 49.3%    |
| CPU peak           | 55.9%    |
| RAM peak           | 45.4%    |
| Errors             | 0        |
| Error rate         | 0%       |
| Status             | STABLE   |
+--------------------+----------+

Testing 400 concurrent connections...
+--------------------+-----------+
| Metric             | Result    |
+--------------------+-----------+
| Requests/sec       | 6443.65   |
| Requests completed | 64680     |
| Average latency    | 61.32 ms  |
| P50                | 58.9 ms   |
| P95                | 90.04 ms  |
| P99                | 109.49 ms |
| CPU average        | 57.3%     |
| CPU peak           | 67%       |
| RAM peak           | 45.7%     |
| Errors             | 0         |
| Error rate         | 0%        |
| Status             | STABLE    |
+--------------------+-----------+

CAPACITY REPORT
───────────────────────────────────────────────────────
+--------------------+-----------+
| Metric             | Value     |
+--------------------+-----------+
| Maximum stable RPS | 6444      |
| Stable concurrency | 400       |
| P95 latency        | 90.04 ms  |
| P99 latency        | 109.49 ms |
| CPU average        | 57.3%     |
| RAM peak           | 45.7%     |
+--------------------+-----------+

RESOURCE RECOMMENDATIONS
CPU capacity appears sufficient.
RAM capacity appears sufficient.

For accurate measurements, run the load generator on a separate machine.
CPU and RAM values represent the entire host, not Neo workers alone.
```

## Laravel 
```
Laravel Capacity Analyzer
───────────────────────────────────────────────────────
+--------------------+-------------------------------------+
| Resource           | Value                               |
+--------------------+-------------------------------------+
| Target             | http://127.0.0.1:8111/benchmark/mix |
| CPU cores          | 12                                  |
| Total RAM          | 15.54 GB                            |
| Duration           | 15 seconds per stage                |
| Concurrency levels | 10, 25, 50, 100, 200, 400           |
+--------------------+-------------------------------------+

Testing 10 concurrent connections...
+--------------------+----------+
| Metric             | Result   |
+--------------------+----------+
| Requests/sec       | 2,049.93 |
| Requests completed | 30749    |
| Average latency    | 4.76 ms  |
| P50                | 3.38 ms  |
| P95                | 9.67 ms  |
| P99                | 14.40 ms |
| CPU average        | 79.2%    |
| CPU peak           | 91.6%    |
| RAM peak           | 57.0%    |
| Errors             | 0        |
| Error rate         | 0.000%   |
| RPS gain           | N/A      |
| Latency degraded   | NO       |
| Status             | STABLE   |
+--------------------+----------+

Testing 25 concurrent connections...
+--------------------+----------+
| Metric             | Result   |
+--------------------+----------+
| Requests/sec       | 2,262.73 |
| Requests completed | 33941    |
| Average latency    | 10.92 ms |
| P50                | 8.76 ms  |
| P95                | 20.10 ms |
| P99                | 27.96 ms |
| CPU average        | 88.5%    |
| CPU peak           | 97.3%    |
| RAM peak           | 56.6%    |
| Errors             | 0        |
| Error rate         | 0.000%   |
| RPS gain           | 10.38%   |
| Latency degraded   | NO       |
| Status             | STABLE   |
+--------------------+----------+

Testing 50 concurrent connections...
+--------------------+----------+
| Metric             | Result   |
+--------------------+----------+
| Requests/sec       | 2,264.13 |
| Requests completed | 33962    |
| Average latency    | 21.97 ms |
| P50                | 18.79 ms |
| P95                | 36.71 ms |
| P99                | 66.22 ms |
| CPU average        | 89.6%    |
| CPU peak           | 97.3%    |
| RAM peak           | 56.9%    |
| Errors             | 0        |
| Error rate         | 0.000%   |
| RPS gain           | 0.06%    |
| Latency degraded   | YES      |
| Status             | STABLE   |
+--------------------+----------+

Testing 100 concurrent connections...
+--------------------+-----------+
| Metric             | Result    |
+--------------------+-----------+
| Requests/sec       | 2,200.13  |
| Requests completed | 33002     |
| Average latency    | 45.42 ms  |
| P50                | 40.29 ms  |
| P95                | 67.90 ms  |
| P99                | 289.07 ms |
| CPU average        | 90.3%     |
| CPU peak           | 98.0%     |
| RAM peak           | 57.1%     |
| Errors             | 0         |
| Error rate         | 0.000%    |
| RPS gain           | -2.83%    |
| Latency degraded   | YES       |
| Status             | STABLE    |
+--------------------+-----------+

Testing 200 concurrent connections...
+--------------------+-----------+
| Metric             | Result    |
+--------------------+-----------+
| Requests/sec       | 1,998.93  |
| Requests completed | 29984     |
| Average latency    | 100.22 ms |
| P50                | 89.30 ms  |
| P95                | 152.36 ms |
| P99                | 381.48 ms |
| CPU average        | 93.0%     |
| CPU peak           | 98.0%     |
| RAM peak           | 57.5%     |
| Errors             | 0         |
| Error rate         | 0.000%    |
| RPS gain           | -9.14%    |
| Latency degraded   | YES       |
| Status             | STABLE    |
+--------------------+-----------+

Testing 400 concurrent connections...
+--------------------+-----------+
| Metric             | Result    |
+--------------------+-----------+
| Requests/sec       | 2,174.07  |
| Requests completed | 32611     |
| Average latency    | 185.28 ms |
| P50                | 167.43 ms |
| P95                | 397.21 ms |
| P99                | 493.26 ms |
| CPU average        | 91.8%     |
| CPU peak           | 98.3%     |
| RAM peak           | 57.7%     |
| Errors             | 0         |
| Error rate         | 0.000%    |
| RPS gain           | 8.76%     |
| Latency degraded   | YES       |
| Status             | SATURATED |
+--------------------+-----------+
Capacity threshold reached.

CAPACITY REPORT
───────────────────────────────────────────────────────
+----------------------------+----------+
| Metric                     | Value    |
+----------------------------+----------+
| Maximum stable RPS         | 2,264    |
| Maximum stable concurrency | 50       |
| Recommended concurrency    | 25       |
| P95 latency                | 36.71 ms |
| P99 latency                | 66.22 ms |
| CPU average                | 89.6%    |
| RAM peak                   | 56.9%    |
| Throughput plateau         | 50       |
| Saturation point           | 50       |
+----------------------------+----------+
```