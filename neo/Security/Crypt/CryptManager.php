<?php

declare(strict_types=1);

namespace Neo\Security\Crypt;

use Random\RandomException;

/**
 * Всё, что связано с хешами и шифрованием, в одном месте.
 *
 * - пароли: {@see hash()}, {@see verify()}, {@see needsRehash()};
 * - подписи и токены: {@see hmac()}, {@see sign()}, {@see verifySigned()}, {@see token()};
 * - CSRF без сессии: {@see csrfToken()}, {@see verifyCsrfToken()};
 * - шифрование AES-256-GCM: {@see encrypt()}, {@see decrypt()}.
 *
 * Из одного ключа приложения через HKDF выводятся два независимых ключа:
 * один для подписей, другой для шифрования. Так компрометация одного
 * способа использования ключа не открывает второй.
 */
final class CryptManager
{
    private const CIPHER = 'aes-256-gcm';
    private const FORMAT_VERSION = "\x01";
    private const IV_LENGTH = 12;
    private const TAG_LENGTH = 16;

    /** Алгоритмы, разрешённые для {@see digest()}. Слабые (md5, sha1, crc) намеренно исключены. */
    private const DIGEST_ALGORITHMS = ['sha256', 'sha384', 'sha512', 'sha3-256', 'sha3-512'];

    private string $encryptionKey;
    private string $signingKey;

    /**
     * @param string               $appKey          Секрет приложения, не короче 32 байт. Сгенерировать: {@see generateKey()}.
     * @param array<string, mixed> $passwordOptions Параметры хеширования паролей (`memory_cost`, `time_cost`, `threads`
     *                                              для Argon2id или `cost` для bcrypt). По умолчанию берутся безопасные
     *                                              значения PHP; занижать их стоит только в тестах.
     *
     * @throws \InvalidArgumentException Если ключ слишком короткий.
     */
    public function __construct(string $appKey, private readonly array $passwordOptions = [])
    {
        if (strlen($appKey) < 32) {
            throw new \InvalidArgumentException(
                'Ключ приложения должен быть не короче 32 байт. Сгенерируйте его через Crypt::generateKey().'
            );
        }

        $this->encryptionKey = hash_hkdf('sha256', $appKey, 32, 'engine.encryption');
        $this->signingKey = hash_hkdf('sha256', $appKey, 32, 'engine.signing');
    }

    /**
     * Генерирует новый случайный ключ приложения (64 hex-символа).
     */
    public static function generateKey(): string
    {
        return bin2hex(random_bytes(32));
    }

    // ------------------------------------------------------------------
    // Пароли
    // ------------------------------------------------------------------

    /**
     * Хеширует пароль (Argon2id, а если он не собран в PHP, то bcrypt).
     *
     * Соль генерируется автоматически и хранится внутри результата.
     * Внимание: bcrypt обрезает пароль до 72 байт, Argon2id нет.
     */
    public function hash(string $password): string
    {
        return password_hash($password, $this->passwordAlgorithm(), $this->passwordOptions);
    }

    /**
     * Проверяет пароль по хешу, полученному из {@see hash()}.
     */
    public function verify(string $password, string $hash): bool
    {
        return password_verify($password, $hash);
    }

    /**
     * Нужно ли пересоздать хеш (например, после смены алгоритма или параметров).
     * Вызывайте после успешного входа и сохраняйте новый хеш.
     */
    public function needsRehash(string $hash): bool
    {
        return password_needs_rehash($hash, $this->passwordAlgorithm(), $this->passwordOptions);
    }

    // ------------------------------------------------------------------
    // Хеши, подписи, токены
    // ------------------------------------------------------------------

    /**
     * Обычный (не парольный) хеш данных, например для ключей кэша и контрольных сумм.
     *
     * @param string $algo Один из: sha256, sha384, sha512, sha3-256, sha3-512.
     *
     * @return string Hex-строка.
     *
     * @throws \InvalidArgumentException Если алгоритм не разрешён.
     */
    public function digest(string $data, string $algo = 'sha256'): string
    {
        if (!in_array($algo, self::DIGEST_ALGORITHMS, true) || !in_array($algo, hash_algos(), true)) {
            throw new \InvalidArgumentException(
                sprintf('Алгоритм "%s" не разрешён. Доступны: %s.', $algo, implode(', ', self::DIGEST_ALGORITHMS))
            );
        }

        return hash($algo, $data);
    }

    /**
     * HMAC-SHA256 с ключом приложения.
     *
     * @param string $context Назначение подписи ("csrf", "cookie"...). Подпись для одного
     *                        контекста не подойдёт к другому.
     *
     * @return string Hex-строка.
     */
    public function hmac(string $data, string $context = ''): string
    {
        return hash_hmac('sha256', $context . "\x00" . $data, $this->signingKey);
    }

    /**
     * Подписывает значение. Само значение НЕ шифруется, его можно прочитать, но нельзя подделать.
     * Результат безопасен для кук и URL.
     *
     * @see verifySigned()
     */
    public function sign(string $value, string $context = ''): string
    {
        $payload = self::encode($value);
        $mac = hash_hmac('sha256', $context . "\x00" . $payload, $this->signingKey, true);

        return $payload . '.' . self::encode($mac);
    }

    /**
     * Проверяет подпись и возвращает исходное значение.
     *
     * @return string|null Значение или null, если подпись неверна или контекст другой.
     */
    public function verifySigned(string $signed, string $context = ''): ?string
    {
        $parts = explode('.', $signed);

        if (count($parts) !== 2) {
            return null;
        }

        [$payload, $mac] = $parts;
        $expected = hash_hmac('sha256', $context . "\x00" . $payload, $this->signingKey, true);
        $given = self::decode($mac);

        if ($given === null || !hash_equals($expected, $given)) {
            return null;
        }

        return self::decode($payload);
    }

    /**
     * Проверяет CSRF-токен и возвращает причину результата.
     *
     * @return string 'ok', 'expired' или 'invalid'.
     */
    public function csrfTokenStatus(string $token, string $binding = ''): string
    {
        $value = $this->verifySigned($token, 'csrf|' . $binding);

        if ($value === null) {
            return 'invalid';
        }

        $parts = explode('|', $value);

        if (
            count($parts) !== 2
            || $parts[0] === ''
            || !ctype_digit($parts[1])
        ) {
            return 'invalid';
        }

        if ((int) $parts[1] < time()) {
            return 'expired';
        }

        return 'ok';
    }

    /**
     * Случайный токен, безопасный для URL (base64url без «=»).
     *
     * @param int $bytes Количество случайных байт, минимум 16.
     * @throws RandomException
     */
    public function token(int $bytes = 32): string
    {
        if ($bytes < 16) {
            throw new \InvalidArgumentException('Токен короче 16 байт небезопасен.');
        }

        return self::encode(random_bytes($bytes));
    }

    /**
     * Сравнение строк за постоянное время (защита от timing-атак).
     */
    public function equals(string $known, string $given): bool
    {
        return hash_equals($known, $given);
    }

    // ------------------------------------------------------------------
    // CSRF
    // ------------------------------------------------------------------

    /**
     * Создаёт CSRF-токен без хранения на сервере: случайная строка и срок жизни, подписанные HMAC.
     *
     * @param string $binding Привязка токена, обычно идентификатор сессии. Токен, выданный
     *                        для одной привязки, не пройдёт проверку с другой.
     * @param int $ttl Время жизни в секундах.
     * @throws RandomException
     */
    public function csrfToken(string $binding = '', int $ttl = 7200): string
    {
        $value = self::encode(random_bytes(16)) . '|' . (time() + $ttl);

        return $this->sign($value, 'csrf|' . $binding);
    }

    /**
     * Проверяет токен из {@see csrfToken()}: подпись, привязку и срок жизни.
     */
    public function verifyCsrfToken(string $token, string $binding = ''): bool
    {
        $value = $this->verifySigned($token, 'csrf|' . $binding);

        if ($value === null) {
            return false;
        }

        $parts = explode('|', $value);

        return count($parts) === 2 && ctype_digit($parts[1]) && (int) $parts[1] >= time();
    }

    // ------------------------------------------------------------------
    // Шифрование
    // ------------------------------------------------------------------

    /**
     * Шифрует строку (AES-256-GCM): данные нельзя ни прочитать, ни подменить.
     *
     * @param string $context Дополнительные аутентифицируемые данные (AAD). Расшифровать
     *                        можно только с тем же контекстом, например с id пользователя.
     *
     * @return string Строка base64url: версия формата, nonce, тег, шифртекст.
     *
     * @throws \RuntimeException|RandomException Если OpenSSL не смог зашифровать.
     */
    public function encrypt(string $plaintext, string $context = ''): string
    {
        $this->requireOpenssl();

        $iv = random_bytes(self::IV_LENGTH);
        $tag = '';

        $cipher = openssl_encrypt(
            $plaintext,
            self::CIPHER,
            $this->encryptionKey,
            OPENSSL_RAW_DATA,
            $iv,
            $tag,
            $context,
            self::TAG_LENGTH
        );

        if ($cipher === false) {
            throw new \RuntimeException('Не удалось зашифровать данные: ' . openssl_error_string());
        }

        return self::encode(self::FORMAT_VERSION . $iv . $tag . $cipher);
    }

    /**
     * Расшифровывает строку из {@see encrypt()}.
     *
     * @return string|null Открытый текст или null, если данные повреждены, подделаны,
     *                     зашифрованы другим ключом или контекст не совпал.
     */
    public function decrypt(string $payload, string $context = ''): ?string
    {
        $this->requireOpenssl();

        $raw = self::decode($payload);
        $minimum = 1 + self::IV_LENGTH + self::TAG_LENGTH;

        if ($raw === null || strlen($raw) < $minimum || $raw[0] !== self::FORMAT_VERSION) {
            return null;
        }

        $iv = substr($raw, 1, self::IV_LENGTH);
        $tag = substr($raw, 1 + self::IV_LENGTH, self::TAG_LENGTH);
        $cipher = substr($raw, $minimum);

        $plain = openssl_decrypt($cipher, self::CIPHER, $this->encryptionKey, OPENSSL_RAW_DATA, $iv, $tag, $context);

        return $plain === false ? null : $plain;
    }

    // ------------------------------------------------------------------
    // Внутреннее
    // ------------------------------------------------------------------

    /**
     * Шифрование идёт через OpenSSL. Хеши и подписи от него не зависят и работают без него.
     *
     * @throws \RuntimeException Если расширение не включено.
     */
    private function requireOpenssl(): void
    {
        if (!extension_loaded('openssl')) {
            throw new \RuntimeException('Для шифрования нужно расширение PHP openssl: в php.ini уберите «;» перед extension=openssl и проверьте командой «php -m».');
        }
    }

    private function passwordAlgorithm(): string
    {
        return defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_BCRYPT;
    }

    private static function encode(string $raw): string
    {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }

    private static function decode(string $encoded): ?string
    {
        $decoded = base64_decode(strtr($encoded, '-_', '+/'), true);

        return $decoded === false ? null : $decoded;
    }
}
