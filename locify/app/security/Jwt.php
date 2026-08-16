<?php

declare(strict_types=1);

/**
 * Native HMAC-SHA256 JWT implementation (no external dependencies).
 * Tokens carry identity, role scope and expiry; RBAC is enforced server-side
 * against the database for every protected request.
 */

final class Jwt
{
    public static function encode(array $payload, ?int $ttl = null): string
    {
        $secret = (string)Config::get('security.jwt_secret', '');
        if (strlen($secret) < 32) {
            throw new RuntimeException('JWT_SECRET is not configured (min 32 chars).');
        }
        $header = ['alg' => 'HS256', 'typ' => 'JWT'];
        $now = time();
        $claims = [
            'iat' => $now,
            'exp' => $now + ($ttl ?? (int)Config::get('security.jwt_ttl', 900)),
            'jti' => self::randomToken(16),
        ];
        $claims = array_merge($claims, $payload);

        $segments = [
            self::b64(json_encode($header, JSON_UNESCAPED_SLASHES)),
            self::b64(json_encode($claims, JSON_UNESCAPED_SLASHES)),
        ];
        $signature = hash_hmac('sha256', implode('.', $segments), $secret, true);
        $segments[] = self::b64($signature);
        return implode('.', $segments);
    }

    /** @return array|null decoded claims, or null when invalid/expired */
    public static function decode(string $token): ?array
    {
        $secret = (string)Config::get('security.jwt_secret', '');
        $parts = explode('.', $token);
        if (count($parts) !== 3) {
            return null;
        }
        $signature = self::unb64($parts[2]);
        $expected = hash_hmac('sha256', $parts[0] . '.' . $parts[1], $secret, true);
        if (!is_string($signature) || !hash_equals($expected, $signature)) {
            return null;
        }
        $claims = json_decode(self::unb64($parts[1]) ?: '', true);
        if (!is_array($claims) || !isset($claims['exp'])) {
            return null;
        }
        if (time() >= (int)$claims['exp']) {
            return null;
        }
        return $claims;
    }

    public static function b64(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    public static function unb64(string $data): ?string
    {
        $padded = str_pad(strtr($data, '-_', '+/'), strlen($data) % 4 === 0 ? 0 : (4 - strlen($data) % 4), '=');
        return base64_decode($padded, true);
    }

    public static function randomToken(int $bytes = 24): string
    {
        return bin2hex(random_bytes($bytes));
    }
}
