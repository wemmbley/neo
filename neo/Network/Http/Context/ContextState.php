<?php

declare(strict_types=1);

namespace Neo\Network\Http\Context;

use Neo\Network\Http\Contracts\RequestContract;
use Neo\Network\Http\Contracts\ResponseContract;

/**
 * Всё, что принадлежит одному запросу. Объект (а не массив), чтобы изменения не терялись
 * при копировании и не требовали повторной записи в хранилище.
 *
 * @internal
 */
final class ContextState
{
    /** @var array<string, mixed> */
    public array $data = [];

    /** @var array<string, mixed> scoped-сервисы контейнера */
    public array $scoped = [];

    public function __construct(
        public readonly RequestContract $request,
        public readonly ResponseContract $response,
    ) {}
}
