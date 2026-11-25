<?php

declare(strict_types=1);

namespace Neo\Network\ResourceAPI;

use Neo\Network\ResourceAPI\Exceptions\ForbiddenResourceAPIException;
use Neo\Network\ResourceAPI\Exceptions\RecordNotFoundResourceAPIException;
use Neo\Network\ResourceAPI\Exceptions\UnauthenticatedResourceAPIException;
use Neo\Security\Authentication\AuthManager;
use Neo\Security\Authorization\Gate\GateManager;

/**
 * Адаптер Auth: решает через Gate и политики моделей.
 *
 * У модели нет политики: ресурс не вмешивается, действует только `$actions`.
 * Политика есть: каждое действие проходит через неё, а метод политики, которого нет, это отказ
 * (так делает сам Gate), поэтому для открытого действия метод нужно объявить.
 */
final class GateResourceAuthorizer implements ResourceAuthorizer
{
    public function __construct(
        private readonly GateManager $gate,
        private readonly AuthManager $auth,
    ) {}

    public function authorize(string $ability, string|object $subject): void
    {
        if (!$this->gate->hasPolicy($subject)) {
            return;
        }

        $response = $this->gate->inspect($ability, $subject);

        if ($response->allowed()) {
            return;
        }

        # Политика может спрятать запись (denyAsNotFound).
        if ($response->status() === 404) {
            throw new RecordNotFoundResourceAPIException();
        }

        if (!$this->auth->guard()->check()) {
            throw new UnauthenticatedResourceAPIException();
        }

        throw new ForbiddenResourceAPIException($response->message());
    }

    public function can(string $ability, string|object $subject): bool
    {
        return !$this->gate->hasPolicy($subject) || $this->gate->allows($ability, $subject);
    }
}
