<?php

declare(strict_types=1);

/**
 * Sliding-window rate limiter backed by the rate_limit table.
 * Keys: "ip:auth" and "user:<uuid>:<scope>" are both checked per request.
 */

final class RateLimiter
{
    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    /** @return bool true when the request is allowed */
    public function allow(string $bucket, int $limitPerMinute, string $ip): bool
    {
        $key = hash('sha256', $bucket . '|' . $ip);
        $window = date('Y-m-d H:i', (int)floor(time() / 60) * 60);

        $stmt = $this->db->prepare(
            'INSERT INTO rate_limit (bucket_key, window_start, count) VALUES (?, ?, 1)
             ON DUPLICATE KEY UPDATE count = count + 1'
        );
        $stmt->execute([$key, $window]);

        $stmt = $this->db->prepare(
            'SELECT SUM(count) AS total FROM rate_limit
             WHERE bucket_key = ? AND window_start BETWEEN ? AND ?'
        );
        $stmt->execute([$key, date('Y-m-d H:i', time() - 120), $window]);
        $total = (int)($stmt->fetch(PDO::FETCH_ASSOC)['total'] ?? 0);

        return $total <= $limitPerMinute;
    }
}
