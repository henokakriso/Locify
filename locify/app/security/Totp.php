<?php

declare(strict_types=1);

/**
 * RFC 6238 TOTP (HMAC-SHA1) with Base32 — zero-dependency implementation.
 * Time step: 30 s, 6-digit codes, 1-step tolerance on each side.
 */

final class Totp
{
    public const STEP = 30;
    public const DIGITS = 6;
    public const WINDOW = 1;

    public static function generateSecret(int $bytes = 20): string
    {
        $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $secret = '';
        for ($i = 0; $i < $bytes; $i++) {
            $secret .= $alphabet[random_int(0, 31)];
        }
        return $secret;
    }

    /** Validate a 6-digit code for the given Base32 secret. */
    public static function verify(string $secret, string $code, ?int $timestamp = null): bool
    {
        $code = trim($code);
        if (!preg_match('/^\d{6}$/', $code)) {
            return false;
        }
        $counter = intdiv($timestamp ?? time(), self::STEP);
        for ($offset = -self::WINDOW; $offset <= self::WINDOW; $offset++) {
            if (hash_equals(self::codeAtCounter($secret, $counter + $offset), $code)) {
                return true;
            }
        }
        return false;
    }

    public static function codeAt(string $secret, ?int $timestamp = null): string
    {
        return self::codeAtCounter($secret, intdiv($timestamp ?? time(), self::STEP));
    }

    private static function codeAtCounter(string $secret, int $counter): string
    {
        $key = self::base32Decode($secret);
        if ($key === '') {
            return '';
        }
        $message = pack('N*', 0, $counter);
        $hash = hash_hmac('sha1', $message, $key, true);
        $offset = ord($hash[strlen($hash) - 1]) & 0x0f;
        $binary = ((ord($hash[$offset]) & 0x7f) << 24)
            | ((ord($hash[$offset + 1]) & 0xff) << 16)
            | ((ord($hash[$offset + 2]) & 0xff) << 8)
            | (ord($hash[$offset + 3]) & 0xff);
        return str_pad((string)($binary % 10 ** self::DIGITS), self::DIGITS, '0', STR_PAD_LEFT);
    }

    /** otpauth:// URI for authenticator apps (issuer + account). */
    public static function otpauthUri(string $secret, string $account, string $issuer = 'LOCIFY'): string
    {
        $label = rawurlencode($issuer . ':' . $account);
        $query = http_build_query([
            'secret' => $secret,
            'issuer' => $issuer,
            'algorithm' => 'SHA1',
            'digits' => self::DIGITS,
            'period' => self::STEP,
        ]);
        return 'otpauth://totp/' . $label . '?' . $query;
    }

    /** Generate n one-time recovery codes (XXXX-XXXX) for offline use. */
    public static function recoveryCodes(int $count = 10): array
    {
        $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        $codes = [];
        for ($i = 0; $i < $count; $i++) {
            $part = '';
            for ($j = 0; $j < 8; $j++) {
                $part .= $alphabet[random_int(0, strlen($alphabet) - 1)];
            }
            $codes[] = substr($part, 0, 4) . '-' . substr($part, 4);
        }
        return $codes;
    }

    /** Conditional strict comparison for recovery codes. */
    public static function codeHash(string $code): string
    {
        return hash('sha256', $code);
    }

    /** Base32 decode (RFC 4648, uppercase/lowercase). */
    private static function base32Decode(string $input): string
    {
        $input = strtoupper(preg_replace('/[\s\-=]/', '', $input) ?? '');
        $map = array_flip(str_split('ABCDEFGHIJKLMNOPQRSTUVWXYZ234567'));
        $buffer = 0;
        $bits = 0;
        $out = '';
        foreach (str_split($input) as $char) {
            if (!isset($map[$char])) {
                return '';
            }
            $buffer = ($buffer << 5) | $map[$char];
            $bits += 5;
            if ($bits >= 8) {
                $out .= chr(($buffer >> ($bits - 8)) & 0xff);
                $bits -= 8;
            }
        }
        return $out;
    }
}