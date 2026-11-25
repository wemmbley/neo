<?php

declare(strict_types=1);

namespace Neo\Network\ResourceAPI;

use Neo\Network\ResourceAPI\Exceptions\ForbiddenResourceAPIException;
use Neo\Network\ResourceAPI\Exceptions\UnauthenticatedResourceAPIException;

/**
 * Граница между ResourceAPI и модулем Auth. Ядро ничего не знает про Gate и пользователей:
 * оно спрашивает этот контракт. Реализация по умолчанию {@see GateResourceAuthorizer};
 * без неё (Auth не подключён) действует только `$actions`.
 */
interface ResourceAuthorizer
{
    /**
     * Способности: `viewAny`, `view`, `create`, `update`, `delete`.
     * Субъект: класс модели (`viewAny`, `create`) или сама запись (`view`, `update`, `delete`).
     *
     * @param class-string|object $subject
     *
     * @throws UnauthenticatedResourceAPIException пользователь не опознан
     * @throws ForbiddenResourceAPIException       отказано
     */
    public function authorize(string $ability, string|object $subject): void;

    /**
     * То же без исключения: можно ли. Используется там, где отказ не ошибка запроса
     * (связи в include и фильтрах, документация).
     *
     * @param class-string|object $subject
     */
    public function can(string $ability, string|object $subject): bool;
}
