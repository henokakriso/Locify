<?php

declare(strict_types=1);

/** UUID v4 generator (RFC 4122). */
function uuid4(): string
{
    $b = random_bytes(16);
    $b[6] = chr((ord($b[6]) & 0x0f) | 0x40);
    $b[8] = chr((ord($b[8]) & 0x3f) | 0x80);
    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($b), 4));
}

/** Mask the middle of a value for display (e.g., phone numbers). */
function maskMiddle(string $value, int $visibleStart = 3, int $visibleEnd = 2): string
{
    $length = strlen($value);
    if ($length <= $visibleStart + $visibleEnd) {
        return str_repeat('*', $length);
    }
    return substr($value, 0, $visibleStart)
        . str_repeat('*', $length - $visibleStart - $visibleEnd)
        . substr($value, -$visibleEnd);
}

/** Human-readable application number, e.g. LOC-2026-000123. */
function nextApplicationNumber(PDO $db, string $adminUnitCode): string
{
    $year = (int)date('Y');
    $key = $adminUnitCode . '-' . $year;
    $db->exec(
        'INSERT INTO number_sequence (seq_key, last_num) VALUES (' . $db->quote($key) . ', 1)
         ON DUPLICATE KEY UPDATE last_num = last_num + 1'
    );
    $stmt = $db->query('SELECT last_num FROM number_sequence WHERE seq_key = ' . $db->quote($key));
    return sprintf('LOC-%d-%06d', $year, (int)$stmt->fetchColumn());
}

/** Human-readable document number, e.g. LOC-DOC-2026-000123. */
function nextDocumentNumber(PDO $db): string
{
    $year = (int)date('Y');
    $key = 'DOC-' . $year;
    $db->exec(
        'INSERT INTO number_sequence (seq_key, last_num) VALUES (' . $db->quote($key) . ', 1)
         ON DUPLICATE KEY UPDATE last_num = last_num + 1'
    );
    $stmt = $db->query('SELECT last_num FROM number_sequence WHERE seq_key = ' . $db->quote($key));
    return sprintf('LOC-DOC-%d-%06d', $year, (int)$stmt->fetchColumn());
}

/** Verification code: XXXX-XXXX-XXXX (12 alphanumeric chars, URL-safe). */
function verificationCode(): string
{
    $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    $parts = [];
    for ($i = 0; $i < 3; $i++) {
        $part = '';
        for ($j = 0; $j < 4; $j++) {
            $part .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        }
        $parts[] = $part;
    }
    return implode('-', $parts);
}

/** True when the given string is a valid UUID. */
function isValidUuid(string $value): bool
{
    return (bool)preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $value);
}

/** Safe HTML output (must be used for every user-provided string in views). */
function e(?string $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
