<?php

declare(strict_types=1);

namespace Neo\Network\ResourceAPI;

use LogicException;

/**
 * Реестр ресурсов: что зарегистрировано, по каким адресам и для каких моделей.
 *
 * Нужен трём вещам: связи (фильтр, сортировка и include работают только через связанную модель,
 * у которой есть свой ресурс), документация (карта всего API) и единый формат ошибок
 * (какие адреса принадлежат API). Заполняется при загрузке приложения и дальше только читается,
 * поэтому безопасен при одновременной обработке запросов.
 */
final class ResourceRegistry
{
    /** @var list<array{resource: ResourceAPI, uri: string, prefix: string, routes: list<array{method: string, uri: string, action: string, name: ?string}>}> */
    private array $entries = [];

    /** @var array<class-string, ResourceAPI> первый ресурс модели */
    private array $byModel = [];

    /** @var list<string> адреса просмотрщика документации */
    private array $documentation = [];

    /**
     * @param list<array{method: string, uri: string, action: string, name: ?string}> $routes
     */
    public function register(ResourceAPI $resource, string $uri, array $routes): void
    {
        $uri = self::normalize($uri);

        foreach ($this->documentation as $docs) {
            if (self::overlaps($uri, $docs)) {
                throw new LogicException("Адрес ресурса «{$uri}» занят просмотрщиком документации ({$docs}): адрес зарезервирован.");
            }
        }

        $prefix = self::normalize(substr($uri, 0, (int) strrpos($uri, '/')));

        $this->entries[] = compact('resource', 'uri', 'prefix', 'routes');
        $this->byModel[$resource->modelClass()] ??= $resource;
    }

    public function registerDocumentation(string $uri): void
    {
        $uri = self::normalize($uri);

        foreach ($this->entries as $entry) {
            if (self::overlaps($entry['uri'], $uri)) {
                throw new LogicException("Адрес документации «{$uri}» занят ресурсом {$entry['resource']->resourceName()}: адрес зарезервирован.");
            }
        }

        $this->documentation[] = $uri;
    }

    /** @param class-string $model */
    public function forModel(string $model): ?ResourceAPI
    {
        return $this->byModel[$model] ?? null;
    }

    /** @return list<array{resource: ResourceAPI, uri: string, prefix: string, routes: list<array{method: string, uri: string, action: string, name: ?string}>}> */
    public function all(): array
    {
        return $this->entries;
    }

    /** Принадлежит ли адрес API: адрес ресурса или любой адрес под префиксом, где живут ресурсы. */
    public function ownsPath(string $path): bool
    {
        $path = self::normalize($path);

        foreach ($this->entries as $entry) {
            if ($entry['prefix'] !== '/' && ($path === $entry['prefix'] || str_starts_with($path, $entry['prefix'] . '/'))) {
                return true;
            }

            if ($path === $entry['uri'] || str_starts_with($path, $entry['uri'] . '/')) {
                return true;
            }
        }

        return false;
    }

    private static function normalize(string $uri): string
    {
        return '/' . trim($uri, '/');
    }

    /** Адреса совпадают или один лежит внутри другого. */
    private static function overlaps(string $a, string $b): bool
    {
        return $a === $b || str_starts_with($a . '/', $b . '/') || str_starts_with($b . '/', $a . '/');
    }
}
