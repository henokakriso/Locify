<?php

declare(strict_types=1);

/**
 * LOCIFY configuration loader.
 * Loads .env if present, otherwise falls back to defaults.
 */

define('LOCIFY_ROOT', dirname(__DIR__));
define('LOCIFY_STORAGE', LOCIFY_ROOT . '/storage');

final class Config
{
    private static array $cache = [];
    private static bool $loaded = false;

    public static function load(): array
    {
        if (self::$loaded) {
            return self::$cache;
        }

        $defaults = [
            'app' => [
                'name'  => 'LOCIFY',
                'env'   => 'production',
                'debug' => false,
                'url'   => 'http://localhost:8080',
            ],
            'db' => [
                'host'     => '127.0.0.1',
                'port'     => 3306,
                'name'     => 'locify',
                'user'     => 'locify',
                'pass'     => '',
                'charset'  => 'utf8mb4',
            ],
            'security' => [
                'app_key'          => '',
                'jwt_secret'       => '',
                'jwt_ttl'          => 900,
                'jwt_refresh_ttl'  => 2592000,
                'mfa_enforced'     => false,
            ],
            'proxy' => [
                'trust' => false,
            ],
            'rate_limit' => [
                'citizen' => 100,
                'officer' => 300,
                'public'  => 30,
            ],
            'payment' => [
                'provider' => 'mock',
                'webhook_secret' => '',
            ],
            'sync' => [
                'interval_seconds' => 300,
                'max_retries'      => 5,
            ],
            'import' => [
                'max_rows' => 500,
            ],
            'language' => [
                'default' => 'am',
                'supported' => ['am', 'en', 'om', 'ti', 'so', 'aa'],
            ],
        ];

        $envFile = LOCIFY_ROOT . '/.env';
        $env = [];
        if (is_readable($envFile)) {
            $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            foreach ($lines ?: [] as $line) {
                $line = trim($line);
                if ($line === '' || str_starts_with($line, '#')) {
                    continue;
                }
                [$key, $value] = array_pad(explode('=', $line, 2), 2, '');
                $env[trim($key)] = trim($value);
            }
        }

        $config = $defaults;

        $map = [
            'APP_NAME'    => ['app', 'name'],
            'APP_ENV'     => ['app', 'env'],
            'APP_DEBUG'   => ['app', 'debug'],
            'APP_URL'     => ['app', 'url'],
            'DB_HOST'     => ['db', 'host'],
            'DB_PORT'     => ['db', 'port'],
            'DB_NAME'     => ['db', 'name'],
            'DB_USER'     => ['db', 'user'],
            'DB_PASS'     => ['db', 'pass'],
            'APP_KEY'     => ['security', 'app_key'],
            'JWT_SECRET'  => ['security', 'jwt_secret'],
            'JWT_TTL'     => ['security', 'jwt_ttl'],
            'JWT_REFRESH_TTL' => ['security', 'jwt_refresh_ttl'],
            'MFA_ENFORCED' => ['security', 'mfa_enforced'],
            'TRUST_PROXY' => ['proxy', 'trust'],
            'RATE_LIMIT_CITIZEN' => ['rate_limit', 'citizen'],
            'RATE_LIMIT_OFFICER' => ['rate_limit', 'officer'],
            'RATE_LIMIT_PUBLIC'  => ['rate_limit', 'public'],
            'PAYMENT_PROVIDER'    => ['payment', 'provider'],
            'PAYMENT_WEBHOOK_SECRET' => ['payment', 'webhook_secret'],
            'SYNC_INTERVAL_SECONDS' => ['sync', 'interval_seconds'],
            'SYNC_MAX_RETRIES' => ['sync', 'max_retries'],
            'DEFAULT_LANGUAGE'  => ['language', 'default'],
        ];

        foreach ($map as $envKey => [$section, $field]) {
            if (isset($env[$envKey]) && $env[$envKey] !== '') {
                $config[$section][$field] = $env[$envKey];
            }
        }

        if (isset($env['SUPPORTED_LANGUAGES'])) {
            $config['language']['supported'] = array_map('trim', explode(',', $env['SUPPORTED_LANGUAGES']));
        }

        if (is_string($config['app']['debug'])) {
            $config['app']['debug'] = strtolower((string)$config['app']['debug']) === 'true';
        }
        if (is_string($config['security']['mfa_enforced'])) {
            $config['security']['mfa_enforced'] = strtolower((string)$config['security']['mfa_enforced']) === 'true';
        }
        if (is_string($config['proxy']['trust'])) {
            $config['proxy']['trust'] = strtolower((string)$config['proxy']['trust']) === 'true';
        }

        self::$cache = $config;
        self::$loaded = true;

        return $config;
    }

    public static function get(string $path, mixed $default = null): mixed
    {
        $value = self::load();
        foreach (explode('.', $path) as $segment) {
            if (!is_array($value) || !array_key_exists($segment, $value)) {
                return $default;
            }
            $value = $value[$segment];
        }
        return $value;
    }
}

if (PHP_SAPI !== 'cli') {
    date_default_timezone_set('Africa/Addis_Ababa');
}
