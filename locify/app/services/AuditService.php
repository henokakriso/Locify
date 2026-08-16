<?php

declare(strict_types=1);

/** Append-only audit logging and security event recording. */

final class Audit
{
    /**
     * Insert an audit entry. The audit_log table is write-only (DB triggers
     * block UPDATE/DELETE); nothing in the application can alter entries.
     */
    public static function log(
        Request $request,
        string $action,
        ?string $resourceType = null,
        ?string $resourceId = null,
        mixed $previousValue = null,
        mixed $newValue = null,
        string $result = 'success',
        ?string $reason = null
    ): void {
        $ctx = Auth::$context;
        Database::run(
            'INSERT INTO audit_log
             (uuid, user_id, role_id, admin_unit_id, ip_address, device_id, action,
              resource_type, resource_id, previous_value_json, new_value_json, result, reason)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                Jwt::randomToken(12),
                $ctx['user_id'] ?? null,
                $ctx['roles'][0]['role_id'] ?? null,
                $ctx['scope_unit'] ?? null,
                $request->ip,
                substr($request->header('user-agent') ?? '', 0, 128),
                $action,
                $resourceType,
                $resourceId,
                $previousValue === null ? null : json_encode($previousValue, JSON_UNESCAPED_UNICODE),
                $newValue === null ? null : json_encode($newValue, JSON_UNESCAPED_UNICODE),
                $result,
                $reason,
            ]
        );
    }

    /** Safe JSON for audit: strips sensitive field values into placeholders. */
    public static function mask(array $data, array $sensitiveKeys = []): array
    {
        foreach ($sensitiveKeys as $key) {
            if (isset($data[$key]) && $data[$key] !== null) {
                $data[$key] = '[REDACTED]';
            }
        }
        return $data;
    }
}

final class SecurityEvent
{
    public static function log(string $eventType, string $severity, Request $request, array $details = []): void
    {
        Database::run(
            'INSERT INTO security_event (event_type, severity, source_ip, user_id, details_json)
             VALUES (?, ?, ?, ?, ?)',
            [
                $eventType,
                $severity,
                $request->ip,
                Auth::$context['user_id'] ?? null,
                json_encode($details, JSON_UNESCAPED_UNICODE),
            ]
        );
    }
}
