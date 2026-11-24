<?php

declare(strict_types=1);

namespace Neo\Network\Cookie\Middleware;

use Closure;
use Neo\Network\Http\Request;
use Neo\Network\Http\Response;
use Neo\Security\Crypt\CryptManager;

/**
 * Шифрует исходящие и расшифровывает входящие куки (AES-256-GCM через Crypt).
 *
 * Шифртекст привязан к имени куки (AAD `cookie|имя`): значение одной куки нельзя
 * подставить в другую. Подделанные, чужие и незашифрованные куки отбрасываются, приложение
 * их не увидит. Куки, которые читает клиентский JS и ставит не сервер, добавьте в
 * `cookie.encrypt_except` (или в $except подкласса).
 *
 * Шифртекст длиннее исходного значения примерно на 40 символов: помните о пределе 4 КБ на куку.
 */
class EncryptCookies
{
    /** @var list<string> */
    protected array $except = [];

    public function __construct(protected readonly CryptManager $crypt) {}

    public function handle(Request $request, Closure $next): Response
    {
        $request->replaceCookies($this->decrypt($request));

        return $this->encrypt($next($request));
    }

    public function isDisabled(string $name): bool
    {
        return in_array($name, [...$this->except, ...(array) config('cookie.encrypt_except', [])], true);
    }

    /** @return array<string, string> */
    protected function decrypt(Request $request): array
    {
        $cookies = [];

        foreach ($request->cookies() as $name => $value) {
            $name = (string) $name;

            if ($this->isDisabled($name)) {
                $cookies[$name] = $value;
                continue;
            }

            if (!is_string($value)) {
                continue;
            }

            $plain = $this->crypt->decrypt($value, 'cookie|' . $name);

            if ($plain !== null) {
                $cookies[$name] = $plain;
            }
        }

        return $cookies;
    }

    protected function encrypt(Response $response): Response
    {
        foreach ($response->cookies() as $cookie) {
            # Удаление куки (пустое значение) шифровать незачем.
            if ($this->isDisabled($cookie->name) || $cookie->value === '') {
                continue;
            }

            $response->withCookie($cookie->withValue($this->crypt->encrypt($cookie->value, 'cookie|' . $cookie->name)));
        }

        return $response;
    }
}
