<?php

namespace Neo\Security\Crypt;

use Neo\Foundation\Kernel\Facade;
use Neo\Foundation\Kernel\FacadeOf;

/**
 * @method static string hash(string $password)
 * @method static bool verify(string $password, string $hash)
 * @method static bool needsRehash(string $hash)
 * @method static string digest(string $data, string $algo = 'sha256')
 * @method static string hmac(string $data, string $context = '')
 * @method static string sign(string $value, string $context = '')
 * @method static ?string verifySigned(string $signed, string $context = '')
 * @method static string csrfTokenStatus(string $token, string $binding = '')
 * @method static string token(int $bytes = 32)
 * @method static bool equals(string $known, string $given)
 * @method static string csrfToken(string $binding = '', int $ttl = 7200)
 * @method static bool verifyCsrfToken(string $token, string $binding = '')
 * @method static string encrypt(string $plaintext, string $context = '')
 * @method static ?string decrypt(string $payload, string $context = '')
 */
#[FacadeOf(CryptManager::class)]
class Crypt extends Facade {}