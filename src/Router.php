<?php

declare(strict_types=1);

namespace PhpSoftBox\Router;

use PhpSoftBox\Router\Exception\RouteNotFoundException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Пример использования:
 *
 * ```
 * <?php
 * $collector = new RouteCollector();
 * $router = new Router(new RouteResolver($collector), new Dispatcher(), $collector);
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

    private function applyParams(ServerRequestInterface $request, RouteMatch $match): ServerRequestInterface
    {
        $routeName = $match->route->name ?? $match->route->path;

        $request = $request
            ->withAttribute('_route', $routeName)
            ->withAttribute('_route_params', $match->params)
            ->withAttribute('_route_handler', $match->route->handler)
            ->withAttribute('_route_scope_bindings', $match->route->scopeBindings);

        foreach ($match->params as $key => $value) {
            $request = $request->withAttribute($key, $value);
        }

        return $request;
    }
}
