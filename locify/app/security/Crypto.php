<?php

declare(strict_types=1);

/**
 * Application-level encryption for sensitive fields.
 * Envelope scheme: a random 256-bit DEK (per value) encrypted under a KEK
 * derived from the APP_KEY. Output: base64(iv + kek_id + nonce + ciphertext).
 */

final class Crypto
{
    private const CIPHER = 'aes-256-gcm';
    private const TAG_LENGTH = 16;

    private static ?string $key = null;
    private static ?string $kekId = null;

    private static function kek(): string
    {
        if (self::$key !== null) {
            return self::$key;
        }
        $raw = Config::get('security.app_key', '');
        if ($raw === '' || $raw === 'Y2hhbmdlLW1lLXBsZWFzZS0ta2V5LWtleS1rZXktbG9uZy1lbm91Z2gtZm9yLWhzMjU2LXNoYTE=') {
            throw new RuntimeException('APP_KEY is not configured. Set a real key in .env.');
        }
        $decoded = base64_decode($raw, true);
        $kek = is_string($decoded) && strlen($decoded) >= 32 ? $decoded : hash('sha256', $raw, true);
        self::$key = hash('sha256', 'locify-kek-v1:' . $kek, true);
        self::$kekId = substr(hash('sha256', (string)$kek), 0, 8);
        return self::$key;
    }

    /** Encrypt a value. Returns "v1:<kek_id>:<base64>" or null for empty input. */
    public static function encrypt(?string $plaintext): ?string
    {
        if ($plaintext === null || $plaintext === '') {
            return null;
        }
        $iv = random_bytes(12);
        $tag = '';
        $ciphertext = openssl_encrypt($plaintext, self::CIPHER, self::kek(), OPENSSL_RAW_DATA, $iv, $tag, '', self::TAG_LENGTH);
        if ($ciphertext === false) {
            throw new RuntimeException('Encryption failed');
        }
        return 'v1:' . self::$kekId . ':' . base64_encode($iv . $tag . $ciphertext);
    }

    /** Decrypt a value produced by encrypt(). */
    public static function decrypt(?string $payload): ?string
    {
        if ($payload === null || $payload === '' || !str_starts_with($payload, 'v1:')) {
            return null;
        }
        $parts = explode(':', $payload, 3);
        if (count($parts) !== 3) {
            return null;
        }
        $blob = base64_decode($parts[2], true);
        if (!is_string($blob) || strlen($blob) < 12 + self::TAG_LENGTH) {
            return null;
        }
        $iv = substr($blob, 0, 12);
        $tag = substr($blob, 12, self::TAG_LENGTH);
        $ciphertext = substr($blob, 12 + self::TAG_LENGTH);
        $plaintext = openssl_decrypt($ciphertext, self::CIPHER, self::kek(), OPENSSL_RAW_DATA, $iv, $tag);
        return is_string($plaintext) ? $plaintext : null;
    }
}
