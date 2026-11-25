# Performance Benchmark Test

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

## Sapi (PHP Default) [4 потока; 100 соеденений; 30с]

```bash 
wrk -t4 -c100 -d30s --latency http://neo.loc
```

Running 30s test @ http://neo.loc
4 threads and 100 connections
Thread Stats   Avg      Stdev     Max   +/- Stdev
Latency    97.07ms   68.98ms 649.05ms   72.08%
Req/Sec   277.11     53.44   494.00     68.33%
Latency Distribution
50%   77.52ms
75%  135.29ms
90%  197.13ms
99%  301.91ms
33146 requests in 30.05s, 35.06MB read
Requests/sec:   1102.95
Transfer/sec:      1.17MB

## Swoole [4 потока; 100 соеденений; 30с]

```bash 
wrk -t4 -c100 -d30s --latency http://127.0.0.1:8000/
```

Running 30s test @ http://127.0.0.1:8000
4 threads and 100 connections
Thread Stats   Avg      Stdev     Max   +/- Stdev
Latency    12.49ms    2.99ms  69.70ms   78.34%
Req/Sec     2.02k   169.74     2.29k    82.08%
Latency Distribution
50%   12.09ms
75%   13.82ms
90%   15.72ms
99%   21.89ms
240881 requests in 30.03s, 257.75MB read
Requests/sec:   8021.53
Transfer/sec:      8.58MB

## Swoole [12 потоков; 100 соеденений; 30с]

```bash 
wrk -t12 -c100 -d30s --latency http://127.0.0.1:8000/
```

Running 30s test @ http://127.0.0.1:8000
12 threads and 100 connections
Thread Stats   Avg      Stdev     Max   +/- Stdev
Latency    12.10ms    2.61ms  51.69ms   77.06%
Req/Sec   664.96     50.40     1.09k    77.44%
Latency Distribution
50%   11.78ms
75%   13.33ms
90%   15.00ms
99%   20.13ms
238589 requests in 30.05s, 255.30MB read
Requests/sec:   7938.89
Transfer/sec:      8.49MB