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

/**
 * Digital Service ID (spec §4): LOC-{year}-{unit}-{service}-{sequence}.
 * e.g. LOC-2026-AA-06-01-RES-000127. The sequence is never reused.
 */
function nextServiceNumber(PDO $db, string $adminUnitCode, string $serviceCode): string
{
    $year = (int)date('Y');
    $unit = compactUnitCode($adminUnitCode);
    $svc = strtoupper(preg_replace('/[^A-Z0-9]/', '', $serviceCode)) ?: 'SVC';
    $key = 'SVC-' . $unit . '-' . $svc . '-' . $year;
    $db->exec(
        'INSERT INTO number_sequence (seq_key, last_num) VALUES (' . $db->quote($key) . ', 1)
         ON DUPLICATE KEY UPDATE last_num = last_num + 1'
    );
    $stmt = $db->query('SELECT last_num FROM number_sequence WHERE seq_key = ' . $db->quote($key));
    return sprintf('LOC-%d-%s-%s-%06d', $year, $unit, $svc, (int)$stmt->fetchColumn());
}

/** Short administrative code: strip the country prefix, keep the last 3 segments. */
function compactUnitCode(string $adminUnitCode): string
{
    $parts = array_values(array_filter(explode('-', trim($adminUnitCode, '-')), fn($p) => $p !== ''));
    if (count($parts) > 3) {
        $parts = array_slice($parts, -3);
    }
    return implode('-', $parts) ?: 'KBL';
}

/** Document number, e.g. LOC-CERT-2026-000018 (service-aware). */
function nextDocumentNumber(PDO $db, ?string $serviceCode = null): string
{
    $year = (int)date('Y');
    $kind = strtoupper(preg_replace('/[^A-Z0-9]/', '', $serviceCode ?? '')) ?: 'CERT';
    $key = 'DOC-' . $kind . '-' . $year;
    $db->exec(
        'INSERT INTO number_sequence (seq_key, last_num) VALUES (' . $db->quote($key) . ', 1)
         ON DUPLICATE KEY UPDATE last_num = last_num + 1'
    );
    $stmt = $db->query('SELECT last_num FROM number_sequence WHERE seq_key = ' . $db->quote($key));
    return sprintf('LOC-%s-%d-%06d', $kind, $year, (int)$stmt->fetchColumn());
}

/** Appointment number, e.g. APT-2026-000193 (spec §18). */
function nextAppointmentNumber(PDO $db): string
{
    $year = (int)date('Y');
    $key = 'APT-' . $year;
    $db->exec(
        'INSERT INTO number_sequence (seq_key, last_num) VALUES (' . $db->quote($key) . ', 1)
         ON DUPLICATE KEY UPDATE last_num = last_num + 1'
    );
    $stmt = $db->query('SELECT last_num FROM number_sequence WHERE seq_key = ' . $db->quote($key));
    return sprintf('APT-%d-%06d', $year, (int)$stmt->fetchColumn());
}

/** Print job number, e.g. PRT-2026-000041 (spec §25). */
function nextPrintJobNumber(PDO $db): string
{
    $year = (int)date('Y');
    $key = 'PRT-' . $year;
    $db->exec(
        'INSERT INTO number_sequence (seq_key, last_num) VALUES (' . $db->quote($key) . ', 1)
         ON DUPLICATE KEY UPDATE last_num = last_num + 1'
    );
    $stmt = $db->query('SELECT last_num FROM number_sequence WHERE seq_key = ' . $db->quote($key));
    return sprintf('PRT-%d-%06d', $year, (int)$stmt->fetchColumn());
}

/** Append-only application status history record (spec §31). */
function recordStatusHistory(string $applicationId, string $status, ?string $previous, ?string $notes, ?string $actorUserId = null): void
{
    Database::run(
        'INSERT INTO application_status_history (id, application_id, status, previous_status, actor_user_id, notes)
         VALUES (?, ?, ?, ?, ?, ?)',
        [uuid4(), $applicationId, $status, $previous, $actorUserId, $notes !== null ? mb_substr($notes, 0, 500) : null]
    );
}

/** Human-readable household number, e.g. HH-ET-AA-06-01-2026-000012. */
function nextHouseholdNumber(PDO $db, string $adminUnitCode): string
{
    $year = (int)date('Y');
    $key = 'HH-' . $adminUnitCode . '-' . $year;
    $db->exec(
        'INSERT INTO number_sequence (seq_key, last_num) VALUES (' . $db->quote($key) . ', 1)
         ON DUPLICATE KEY UPDATE last_num = last_num + 1'
    );
    $stmt = $db->query('SELECT last_num FROM number_sequence WHERE seq_key = ' . $db->quote($key));
    return sprintf('HH-%s-%d-%06d', $adminUnitCode, $year, (int)$stmt->fetchColumn());
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

/** Decrypted full name of a citizen (first middle last), or null. */
function citizenFullName(PDO $db, string $citizenId): ?string
{
    $row = $db->prepare(
        'SELECT first_name_enc, middle_name_enc, last_name_enc FROM citizen WHERE id = ?'
    );
    $row->execute([$citizenId]);
    $c = $row->fetch();
    if ($c === false) {
        return null;
    }
    $parts = array_filter([
        Crypto::decrypt($c['first_name_enc'] ?? null) ?? '',
        Crypto::decrypt($c['middle_name_enc'] ?? null) ?? '',
        Crypto::decrypt($c['last_name_enc'] ?? null) ?? '',
    ]);
    return implode(' ', $parts) ?: null;
}

/** Safe HTML output (must be used for every user-provided string in views). */
function e(?string $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
