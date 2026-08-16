<?php

declare(strict_types=1);

/** JSON response with a consistent error format. */

final class Response
{
    public static function json(mixed $data, int $status = 200): never
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('X-Content-Type-Options: nosniff');
        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    public static function success(array $data = [], int $status = 200): never
    {
        self::json(array_merge(['success' => true], $data), $status);
    }

    public static function error(string $code, string $message, int $status = 400, array $details = []): never
    {
        $error = ['code' => $code, 'message' => $message];
        if ($details !== []) {
            $error['details'] = $details;
        }
        self::json(['success' => false, 'error' => $error], $status);
    }

    public static function notFound(string $message = 'Resource not found'): never
    {
        self::error('NOT_FOUND', $message, 404);
    }

    public static function forbidden(string $message = 'Forbidden'): never
    {
        self::error('FORBIDDEN', $message, 403);
    }

    public static function unauthorized(string $message = 'Unauthorized'): never
    {
        self::error('UNAUTHORIZED', $message, 401);
    }

    public static function validationError(array $details): never
    {
        self::error('VALIDATION_ERROR', 'Validation failed', 422, $details);
    }
}
