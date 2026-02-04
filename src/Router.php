<?php

declare(strict_types=1);

namespace PhpSoftBox\Router;

use PhpSoftBox\Router\Exception\RouteNotFoundException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

use function preg_match;
use function preg_replace;
use function rtrim;
use function str_ends_with;
use function str_replace;

/**
 * Пример использования:
 *
 * ```
 * <?php
 * $router = new Router($routeCollector);
 *
 * $request = new ServerRequest('GET', new Uri('http://example.com/users/123'));
 * $response = $router->handle($request);
 * ```
 */
readonly class Router implements RequestHandlerInterface
{
    public function __construct(
        private RouteResolver $routeResolver,
        private Dispatcher $dispatcher,
        private RouteCollector $routeCollector,
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $match = $this->routeResolver->resolve($request);
        if ($match === null) {
            throw new RouteNotFoundException();
        }

        $request = $this->applyParams($request, $match);

        return $this->dispatcher->dispatch($match->route, $request);
    }

    public function routes(): RouteCollector
    {
        return $this->routeCollector;
    }

    public function urlFor(string $name, array $params = []): string
    {
        $route = $this->routeCollector->getNamedRoutes()[$name] ?? null;
        if (!$route instanceof Route) {
            throw new RouteNotFoundException("Route with name '$name' not found");
        }

        $path = $route->path;

        // Подставляем параметры в обязательные и опциональные плейсхолдеры
        foreach ($params as $key => $value) {
            $path = str_replace(['{' . $key . '}', '{' . $key . '?}'], (string) $value, $path);
        }

        // Удаляем неуказанные опциональные сегменты вида '/{param?}' или '{param?}'
        $path = (string) preg_replace('~/?\{[^}/]+\?}~', '', $path);

        // Если остались обязательные плейсхолдеры — ошибка
        if (preg_match('~\{[^}/]+}~', $path) === 1) {
            throw new RouteNotFoundException("Missing required parameters for route '$name'");
        }

        // Нормализуем множественные слеши
        $path = (string) preg_replace('~//+~', '/', $path);

        // Убираем завершающий слеш, кроме корня
        if ($path !== '/' && str_ends_with($path, '/')) {
            $path = rtrim($path, '/');
        }

        return $path;
    }

    private function applyParams(ServerRequestInterface $request, RouteMatch $match): ServerRequestInterface
    {
        $routeName = $match->route->name ?? $match->route->path;

        $request = $request
            ->withAttribute('_route', $routeName)
            ->withAttribute('_route_params', $match->params)
            ->withAttribute('_route_handler', $match->route->handler);

        foreach ($match->params as $key => $value) {
            $request = $request->withAttribute($key, $value);
        }

        return $request;
    }
}
