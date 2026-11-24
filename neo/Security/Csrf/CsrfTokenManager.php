<?php

declare(strict_types=1);

namespace Neo\Security\Csrf;

use Neo\Network\Http\Contracts\RequestContextContract;
use Neo\Network\Session\SessionManager;
use Neo\Security\Crypt\CryptManager;
use Neo\Security\Xss\Escaper;

/**
 * CSRF-токены, привязанные к сессии пользователя.
 *
 * Токен подписан HMAC и содержит срок жизни ({@see CryptManager::csrfToken()}), на сервере
 * ничего не хранится. Он привязан к id сессии: токен, выданный одному пользователю,
 * другому не подойдёт. Каждый вызов в рамках запроса отдаёт один и тот же токен.
 *
 * Состояние запроса лежит в RequestContext, а не в свойствах: сервис общий для процесса.
 */
final class CsrfTokenManager
{
    private const string CONTEXT_KEY = 'neo.csrf.token';

    public function __construct(
        private readonly CryptManager $crypt,
        private readonly SessionManager $session,
        private readonly RequestContextContract $context,
    ) {}

    public function token(): string
    {
        $id = $this->session->getId();
        $cached = $this->context->get(self::CONTEXT_KEY);

        # После session()->regenerate() id другой: токен выпускается заново.
        if (is_array($cached) && $cached[0] === $id) {
            return $cached[1];
        }

        $token = $this->crypt->csrfToken($id, $this->ttl());
        $this->context->set(self::CONTEXT_KEY, [$id, $token]);

        return $token;
    }

    public function verify(string $token): bool
    {
        return $token !== '' && $this->crypt->verifyCsrfToken($token, $this->session->getId());
    }

    /** Срок жизни токена = время жизни сессии. */
    public function ttl(): int
    {
        return max(60, (int) config('session.lifetime', 120) * 60);
    }

    /** `<input type="hidden" name="_token" value="...">` */
    public function field(): string
    {
        return '<input type="hidden" name="_token" value="' . Escaper::attr($this->token()) . '" autocomplete="off">';
    }
}
