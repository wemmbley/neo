-- Смесь запросов для wrk: разные сценарии с заданными весами.
--   wrk -t4 -c100 -d30s --latency -s neo/Diagnostic/Benchmark/wrk/mix.lua http://127.0.0.1:8000
-- Префикс /benchmark/web/ вместо /benchmark/ прогонит всё через куки, сессию и CSRF.
local prefix = os.getenv("BENCH_PREFIX") or "/benchmark/"

local weights = {
  {"db.pk", 35}, {"db.list", 15}, {"db.join", 5}, {"db.comments", 10}, {"db.pk_orm", 5},
  {"db.insert", 10}, {"db.update", 8}, {"db.transaction", 5}, {"db.counter", 4}, {"db.aggregate", 1},
  {"ping", 2},
}

local total = 0
for _, w in ipairs(weights) do total = total + w[2] end

request = function()
  local roll = math.random(total)
  for _, w in ipairs(weights) do
    roll = roll - w[2]
    if roll <= 0 then return wrk.format("GET", prefix .. w[1]) end
  end
  return wrk.format("GET", prefix .. "db.pk")
end

-- Не 2xx это ошибка сценария (дедлок, таймаут блокировки): wrk покажет их в Non-2xx responses.
done = function(summary, latency, requests)
  io.write(string.format("errors: connect=%d read=%d write=%d status=%d timeout=%d\n",
    summary.errors.connect, summary.errors.read, summary.errors.write, summary.errors.status, summary.errors.timeout))
end
