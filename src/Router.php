<?php

declare(strict_types=1);

namespace PhpSoftBox\Router;

use PhpSoftBox\Profiler\NullProfiler;
use PhpSoftBox\Profiler\ProfilerInterface;
use PhpSoftBox\Router\Exception\RouteNotFoundException;
use PhpSoftBox\Router\Profiler\RouterProfilerCollector;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

use function hrtime;

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
    private ProfilerInterface $profiler;

    public function __construct(
        private RouteResolver $routeResolver,
        private Dispatcher $dispatcher,
        private RouteCollector $routeCollector,
        ?ProfilerInterface $profiler = null,
        private readonly ?RouterProfilerCollector $profilerCollector = null,
    ) {
        $this->profiler = $profiler ?? new NullProfiler();
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $startedAt = hrtime(true);

        $match = $this->profiler->span('router.match', fn (): ?RouteMatch => $this->routeResolver->resolve($request), [
            'method' => $request->getMethod(),
            'path'   => $request->getUri()->getPath(),
            'host'   => $request->getUri()->getHost(),
        ], 'router');

        if ($match === null) {
            $this->profilerCollector?->recordNotFound((hrtime(true) - $startedAt) / 1_000_000);

            throw new RouteNotFoundException();
        }

        $this->profilerCollector?->recordMatch($match->route, (hrtime(true) - $startedAt) / 1_000_000);

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
