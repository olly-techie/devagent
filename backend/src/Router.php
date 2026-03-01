<?php
declare(strict_types=1);

namespace DevAgent;

class Router
{
    private array $routes = [];

    public function get(string $path, array|callable $handler): void
    {
        $this->routes[] = ['GET', $path, $handler];
    }

    public function post(string $path, array|callable $handler): void
    {
        $this->routes[] = ['POST', $path, $handler];
    }

    public function dispatch(): void
    {
        $method = $_SERVER['REQUEST_METHOD'];
        $uri    = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);

        foreach ($this->routes as [$routeMethod, $routePath, $handler]) {
            if ($routeMethod !== $method) continue;

            $pattern = preg_replace('/\{(\w+)\}/', '(?P<$1>[^/]+)', $routePath);
            $pattern = '#^' . $pattern . '$#';

            if (preg_match($pattern, $uri, $matches)) {
                $params = array_filter($matches, 'is_string', ARRAY_FILTER_USE_KEY);

                if (is_callable($handler)) {
                    $handler($params);
                } else {
                    [$class, $action] = $handler;
                    (new $class())->$action($params);
                }
                return;
            }
        }

        jsonResponse(['error' => 'Not found'], 404);
    }
}
