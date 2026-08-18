<?php

declare(strict_types=1);

/** Request abstraction with safe JSON body parsing. */

final class Request
{
    public string $method;
    public string $path;
    public array $query;
    public array $body;
    public array $headers;
    public string $ip;

    public function __construct()
    {
        $this->method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
        $this->path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
        $this->path = '/' . trim($this->path, '/');
        $this->query = $_GET ?? [];
        $this->headers = [];
        foreach ($_SERVER as $key => $value) {
            if (str_starts_with($key, 'HTTP_')) {
                $this->headers[strtolower(str_replace('_', '-', substr($key, 5)))] = $value;
            }
        }
        $this->ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
        // Only trust X-Forwarded-For when the deployment explicitly sits behind
        // a trusted reverse proxy; otherwise the header is client-suppliable.
        if (Config::get('proxy.trust', false) && $requestIp = $this->header('x-forwarded-for')) {
            $this->ip = trim(explode(',', $requestIp)[0]);
        }
        $this->body = $this->parseBody();
    }

    private function parseBody(): array
    {
        $contentType = $this->headers['content-type'] ?? '';
        if (str_contains($contentType, 'application/json')) {
            $raw = file_get_contents('php://input') ?: '';
            $data = json_decode($raw, true);
            return is_array($data) ? $data : [];
        }
        return $_POST ?? [];
    }

    public function input(string $key, mixed $default = null): mixed
    {
        if (!str_contains($key, '.')) {
            return $this->body[$key] ?? $default;
        }
        $value = $this->body;
        foreach (explode('.', $key) as $segment) {
            if (!is_array($value) || !array_key_exists($segment, $value)) {
                return $default;
            }
            $value = $value[$segment];
        }
        return $value;
    }

    public function header(string $name): ?string
    {
        return $this->headers[strtolower($name)] ?? null;
    }

    /** Bearer token from Authorization header. */
    public function bearerToken(): ?string
    {
        $auth = $this->header('authorization');
        if ($auth !== null && preg_match('/^Bearer\s+(.+)$/i', $auth, $m)) {
            return trim($m[1]);
        }
        return null;
    }
}
