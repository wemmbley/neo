#!/usr/bin/env bash
# Матрица замеров: воркеры × размер пула. Каждая конфигурация: запуск сервера, прогрев, wrk,
# и во время wrk снимок загрузки процессов (mysqld / php / wrk в % одного ядра; всего у вас 1200% на 12 потоках).
#
#   bash bin/bench-matrix.sh                    # /benchmark/mix, воркеры "4 6", пулы "4 6 10"
#   WORKERS="6" POOLS="3 4 6" bash bin/bench-matrix.sh /benchmark/mix
#   DURATION=30 bash bin/bench-matrix.sh /benchmark/db.pk
#
# Результат печатается таблицей и дописывается в bench-results.txt.
set -u
cd "$(dirname "$0")/.."

URL_PATH="${1:-/benchmark/mix}"
DURATION="${DURATION:-20}"
WORKERS="${WORKERS:-4 6}"
POOLS="${POOLS:-4 6 10}"
MODE="${MODE:-process}"
PORT="${PORT:-8000}"
OUT="bench-results.txt"

for tool in wrk php top; do
    command -v "$tool" >/dev/null || { echo "Не найдено: $tool"; exit 1; }
done

stop_server() {
    pkill -TERM -f "cli serve --swoole" 2>/dev/null
    sleep 3
    pkill -KILL -f "cli serve --swoole" 2>/dev/null
    sleep 1
}

stop_server
header=$(printf '%-8s %-5s %-9s %-9s %-9s %-7s %-9s %-9s %-9s' workers pool rps p50 p99 non2xx mysqld% php% wrk%)
echo "$header"
{ echo; echo "# $(date '+%F %T') ${URL_PATH} mode=${MODE} duration=${DURATION}s"; echo "$header"; } >> "$OUT"

for w in $WORKERS; do
    for p in $POOLS; do
        DB_POOL_MAX="$p" php cli serve --swoole --workers="$w" --mode="$MODE" --port="$PORT" >/tmp/neo-bench-server.log 2>&1 &
        sleep 5
        wrk -t4 -c100 -d5s "http://127.0.0.1:${PORT}${URL_PATH}" >/dev/null 2>&1   # прогрев: соединения пула, JIT-кэши

        wrk -t4 -c100 -d"${DURATION}s" --latency "http://127.0.0.1:${PORT}${URL_PATH}" >/tmp/neo-bench-wrk.txt 2>&1 &
        WRK_PID=$!
        sleep $((DURATION / 2))
        # Второй кадр top: первый показывает среднее с момента запуска процесса, а не текущую нагрузку.
        top -b -d 3 -n 2 -w 512 | awk '/^top -/{n++} n==2' >/tmp/neo-bench-top.txt
        wait "$WRK_PID"

        rps=$(awk '/Requests\/sec/{print $2}' /tmp/neo-bench-wrk.txt)
        p50=$(awk '$1=="50%"{print $2}' /tmp/neo-bench-wrk.txt)
        p99=$(awk '$1=="99%"{print $2}' /tmp/neo-bench-wrk.txt)
        bad=$(awk '/Non-2xx/{print $NF}' /tmp/neo-bench-wrk.txt)
        mysql=$(awk '$NF ~ /mysqld|mariadbd/ {s+=$9} END{printf "%.0f", s}' /tmp/neo-bench-top.txt)
        phpcpu=$(awk '$NF ~ /^php/ {s+=$9} END{printf "%.0f", s}' /tmp/neo-bench-top.txt)
        wrkcpu=$(awk '$NF == "wrk" {s+=$9} END{printf "%.0f", s}' /tmp/neo-bench-top.txt)

        row=$(printf '%-8s %-5s %-9s %-9s %-9s %-7s %-9s %-9s %-9s' "$w" "$p" "${rps:-?}" "${p50:-?}" "${p99:-?}" "${bad:-0}" "$mysql" "$phpcpu" "$wrkcpu")
        echo "$row"
        echo "$row" >> "$OUT"
        grep -c "PoolExhausted" /tmp/neo-bench-server.log | awk '$1>0{print "  ! PoolExhausted в логе сервера: " $1}'

        stop_server
    done
done
echo "Готово. Результаты также в $OUT"
