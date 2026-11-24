<?php

declare(strict_types=1);

namespace Neo\Security\Headers;

use Neo\Network\Http\Contracts\RequestContextContract;

/**
 * Одноразовое значение (nonce) для Content-Security-Policy, своё на каждый запрос.
 *
 *   <script nonce="{{ csp_nonce() }}">...</script>
 *   CSP: "script-src 'self' 'nonce-{nonce}'"  (плейсхолдер {nonce} подставляет SecurityHeaders)
 */
final class CspNonce
{
    private const string KEY = 'neo.csp.nonce';

    public function __construct(private readonly RequestContextContract $context) {}

    public function get(): string
    {
        $nonce = $this->context->get(self::KEY);

        if (!is_string($nonce)) {
            $nonce = rtrim(strtr(base64_encode(random_bytes(16)), '+/', '-_'), '=');
            $this->context->set(self::KEY, $nonce);
        }

        return $nonce;
    }
}
