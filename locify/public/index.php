<?php

declare(strict_types=1);

/** Front controller — the single entry point for web and API traffic. */

// Serve existing static assets directly (php -S router mode).
if (isset($_SERVER['REQUEST_URI'])) {
    $uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?? '/';
    $candidate = __DIR__ . $uri;
    if ($uri !== '/' && is_file($candidate)) {
        return false;
    }
}

require __DIR__ . '/../config/config.php';
require __DIR__ . '/../app/helpers/functions.php';
require __DIR__ . '/../app/helpers/Calendar.php';
require __DIR__ . '/../app/security/Crypto.php';
require __DIR__ . '/../app/security/Jwt.php';
require __DIR__ . '/../app/security/RateLimiter.php';
require __DIR__ . '/../app/security/Totp.php';
require __DIR__ . '/../app/core/Database.php';
require __DIR__ . '/../app/core/Request.php';
require __DIR__ . '/../app/core/Response.php';
require __DIR__ . '/../app/core/Router.php';
require __DIR__ . '/../app/core/Controller.php';
require __DIR__ . '/../app/middleware/Auth.php';
require __DIR__ . '/../app/services/AuditService.php';
require __DIR__ . '/../app/validators/Validator.php';
require __DIR__ . '/../app/services/CitizenService.php';
require __DIR__ . '/../app/services/WorkflowService.php';
require __DIR__ . '/../app/services/DocumentService.php';
require __DIR__ . '/../app/services/NotificationService.php';
require __DIR__ . '/../app/services/PaymentService.php';
require __DIR__ . '/../app/controllers/AuthController.php';
require __DIR__ . '/../app/controllers/CitizenController.php';
require __DIR__ . '/../app/controllers/ResidentController.php';
require __DIR__ . '/../app/controllers/ServiceController.php';
require __DIR__ . '/../app/controllers/ApiControllers.php';
require __DIR__ . '/../app/controllers/DKSControllers.php';
require __DIR__ . '/../app/controllers/CollaborationControllers.php';
require __DIR__ . '/../api/routes.php';

set_exception_handler(function (Throwable $e) {
    error_log('[LOCIFY] ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
    if (Config::get('app.debug')) {
        Response::error('INTERNAL_ERROR', $e->getMessage(), 500);
    }
    Response::error('INTERNAL_ERROR', 'An unexpected error occurred', 500);
});

$request = new Request();

// ---------- CSRF defense-in-depth: reject cross-origin state-changing requests ----------
// Browsers attach an Origin header to cross-origin fetch/XHR and to same-origin
// POSTs; when it is present it must match the configured application URL or the
// request's own Host header (the same origin as far as the browser is concerned).
$originHeader = $request->header('origin');
if ($originHeader !== null && !in_array($request->method, ['GET', 'HEAD', 'OPTIONS'], true)) {
    $allowed = parse_url((string)Config::get('app.url', ''), PHP_URL_HOST);
    $hostHeader = parse_url((string)($request->header('host') ?? ''), PHP_URL_HOST) ?: (string)($request->header('host') ?? '');
    $originHost = parse_url($originHeader, PHP_URL_HOST);
    $matches = $originHost !== null
        && ($originHost === $allowed || ($hostHeader !== '' && $originHost === $hostHeader));
    if (!$matches) {
        SecurityEvent::log('cross_origin_request_rejected', 'high', $request, ['origin' => $originHeader]);
        Response::forbidden('Cross-origin requests are not allowed');
    }
}

// Security headers
header('X-Frame-Options: DENY');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
header(
    "Content-Security-Policy: default-src 'self'; "
    . "script-src 'self' 'unsafe-inline'; "
    . "style-src 'self' 'unsafe-inline'; "
    . "img-src 'self' data:; "
    . "font-src 'self'; "
    . "connect-src 'self'; "
    . "frame-ancestors 'none'; "
    . "form-action 'self'; "
    . "base-uri 'self'"
);
header('Permissions-Policy: camera=(), microphone=(), geolocation=(), payment=()');
header('Cross-Origin-Resource-Policy: same-origin');
header('X-Permitted-Cross-Domain-Policies: none');
if (Config::get('app.env') === 'production') {
    header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
}

// ---------- API routes ----------
if (str_starts_with($request->path, '/api/')) {
    $router = new Router();
    foreach (locifyRoutes() as $route) {
        $definition = [
            'method' => $route['http'],
            'pattern' => $route['route'],
            'handler' => $route['handler'],
        ];
        if ($route['auth']) {
            $definition['auth'] = true;
            $definition['permission'] = $route['permission'] ?? null;
        }
        $router->add($definition);
    }
    $router->dispatch($request);
}

// ---------- Web views ----------
$view = match (true) {
    $request->path === '/' => 'public/home',
    $request->path === '/login' => 'public/login',
    $request->path === '/register' => 'public/register',
    $request->path === '/verify' => 'public/verify',
    $request->path === '/paper' => 'public/paper',
    $request->path === '/portal' => 'citizen/portal',
    $request->path === '/officer' => 'officer/dashboard',
    $request->path === '/admin' => 'admin/dashboard',
    default => null,
};

if ($view === null) {
    Response::notFound('Page not found');
}

// Views are static pages that consume the API via fetch(); sessions are managed
// client-side with tokens stored in memory (no persistent cookies for tokens).
require __DIR__ . '/../views/' . $view . '.php';
