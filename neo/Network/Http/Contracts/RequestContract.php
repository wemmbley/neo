<?php

declare(strict_types=1);

namespace Neo\Network\Http\Contracts;

interface RequestContract
{
    /** @return array<string, string> */
    public function cookies(): array;

    /** Метод запроса с учётом подмены (_method) для POST-форм. */
    public function method(): string;

    /** Путь без query и без ведущего слэша; `/` для корня. */
    public function path(): string;

    /** Заголовок или, без ключа, все заголовки. */
    public function header(?string $key = null, mixed $default = null): mixed;

    /** IP клиента с учётом доверенных прокси. */
    public function ip(): ?string;

    public function isSecure(): bool;

    public function getContent(): string;

    public function expectsJson(): bool;
}
