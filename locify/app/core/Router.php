<?php

declare(strict_types=1);

/**
 * Lightweight route dispatcher.
 * Route definitions are provided by modules under api/ and wired in config/routes.php.
 */

final class Router
{
    private array $routes = [];

    /** @param array $definition ['method' => 'GET|POST|PUT|DELETE', 'pattern' => '/api/v1/...', 'handler' => [Controller::class,'method'], 'permission' => 'PERM:NAME'|null, 'auth' => bool] */
    public function add(array $definition): void
    {
        $this->routes[] = $definition;
    }

    /** Dispatch a request; returns nothing (response already sent) on match. */
    public function dispatch(Request $request): void
    {
        foreach ($this->routes as $route) {
            if ($route['method'] !== $request->method) {
                continue;
            }
            $regex = $this->compile($route['pattern']);
            if (!preg_match($regex, $request->path, $matches)) {
                continue;
            }

            array_shift($matches); // drop full match
            $params = array_filter($matches, 'is_string', ARRAY_FILTER_USE_KEY);
            $params = array_map(fn($v) => rawurldecode($v), $params);

            $request->routeParams = $params;
            $request->routeDefinition = $route;

            [$controllerClass, $method] = $route['handler'];
            $controller = new $controllerClass();
            if (!method_exists($controller, $method)) {
                Response::error('INTERNAL_ERROR', 'Handler not found', 500);
            }
            $controller->{$method}($request);
        }
        Response::error('NOT_FOUND', 'Route not found', 404);
    }

    private function compile(string $pattern): string
    {
        $pattern = preg_replace('/\{([a-zA-Z0-9_]+)\}/', '(?P<$1>[a-zA-Z0-9\-_\.]+)', $pattern);
        return '#^' . $pattern . '$#';
    }
}
