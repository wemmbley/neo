<?php

declare(strict_types=1);

namespace Neo\Network\Http\Console;

use Neo\Foundation\Console\Commands\Command;
use Neo\Network\Http\Routing\Router;

/** Показывает все зарегистрированные маршруты. */
class RouteListCommand extends Command
{
    protected string $signature = 'route:list {--name= : Фильтр по имени} {--path= : Фильтр по пути}';

    protected string $description = 'Список маршрутов';

    public function handle(): int
    {
        /** @var Router $router */
        $router = kernel(Router::class);
        $rows = [];

        foreach ($router->getRoutes()->getRoutes() as $route) {
            $name = $route->getName() ?? '';
            $uri = $route->uri();

            if (($filter = $this->option('name')) && !str_contains($name, (string) $filter)) {
                continue;
            }

            if (($filter = $this->option('path')) && !str_contains($uri, (string) $filter)) {
                continue;
            }

            $methods = array_values(array_diff($route->methods(), ['HEAD']));

            $middleware = [];
            try {
                foreach ($router->gatherRouteMiddleware($route) as $item) {
                    $middleware[] = is_string($item) ? $item : 'Closure';
                }
            } catch (\Throwable $e) {
                $middleware[] = '! ' . $e->getMessage();
            }

            $rows[] = [implode('|', $methods), $uri, $name, $route->getActionName(), implode(', ', $middleware)];
        }

        if ($rows === []) {
            $this->comment('Маршрутов нет.');

            return self::SUCCESS;
        }

        $widths = [];
        foreach ([['METHOD', 'URI', 'NAME', 'ACTION', 'MIDDLEWARE'], ...$rows] as $row) {
            foreach ($row as $i => $cell) {
                $widths[$i] = max($widths[$i] ?? 0, mb_strlen($cell));
            }
        }

        foreach ([['METHOD', 'URI', 'NAME', 'ACTION', 'MIDDLEWARE'], ...$rows] as $row) {
            $this->line(implode('  ', array_map(
                static fn (string $cell, int $i): string => $cell . str_repeat(' ', $widths[$i] - mb_strlen($cell)),
                $row,
                array_keys($row),
            )));
        }

        $this->newLine();
        $this->info(count($rows) . ' маршрут(ов).');

        return self::SUCCESS;
    }
}
