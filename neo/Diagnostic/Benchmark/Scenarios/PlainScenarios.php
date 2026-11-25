<?php

declare(strict_types=1);

namespace Neo\Diagnostic\Benchmark\Scenarios;

use Neo\Diagnostic\Benchmark\Support\Context;
use Neo\Diagnostic\Benchmark\Support\Scenario;

/** Сценарии без БД: стоимость самого сервера, CPU, блокирующего и неблокирующего ввода-вывода, шаблонов. */
final class PlainScenarios
{
    /** @return list<Scenario> */
    public static function all(): array
    {
        return [
            new Scenario('ping', 'plain', 'Пустой ответ: потолок сервера, роутера и middleware', static fn (): string => 'pong'),

            new Scenario('cpu', 'plain', 'Чистый CPU: 2000 хэшей и сортировка 2000 чисел', static function (): int {
                $hash = 'seed';

                for ($i = 0; $i < 2000; $i++) {
                    $hash = md5($hash . $i);
                }

                $numbers = [];

                for ($i = 0; $i < 2000; $i++) {
                    $numbers[] = random_int(0, 1_000_000);
                }

                sort($numbers);

                return strlen($hash) + $numbers[0];
            }),

            new Scenario('json', 'plain', 'Сборка, encode и decode массива на 300 элементов', static function (): int {
                $data = [];

                for ($i = 0; $i < 300; $i++) {
                    $data[] = ['id' => $i, 'name' => 'item ' . $i, 'tags' => ['a', 'b', 'c'], 'price' => $i * 1.5];
                }

                return count((array) json_decode(json_encode($data, JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR));
            }),

            new Scenario('sleep', 'plain', 'usleep (?ms=10): в SAPI блокирует процесс, в Swoole отдаёт корутину. Главный тест конкурентности', static function (Context $c): int {
                $ms = $c->int('ms', 10, 0, 1000);
                usleep($ms * 1000);

                return $ms;
            }),

            new Scenario('file', 'plain', 'Запись и чтение небольшого файла (файловые хуки Swoole выключены: блокирует воркер)', static function (): int {
                $path = sys_get_temp_dir() . '/neo-bench-' . getmypid() . '.tmp';
                file_put_contents($path, str_repeat('x', 4096));
                $size = strlen((string) file_get_contents($path));
                @unlink($path);

                return $size;
            }),

            new Scenario('view', 'plain', 'Рендер Blade-шаблона на 50 строк без БД', static function (): int {
                $rows = [];

                for ($i = 1; $i <= 50; $i++) {
                    $rows[] = ['id' => $i, 'title' => "Row {$i} <b>escaped</b>", 'score' => $i * 3];
                }

                return strlen((string) view('benchmark::table', ['rows' => $rows]));
            }),
        ];
    }
}
