<?php

declare(strict_types=1);

namespace PhpSoftBox\Router;

use Closure;
use PhpSoftBox\Profiler\NullProfiler;
use PhpSoftBox\Profiler\ProfilerInterface;
use PhpSoftBox\Router\Handler\DefaultHandlerResolver;
use PhpSoftBox\Router\Handler\HandlerResolverInterface;
use PhpSoftBox\Router\Middleware\DefaultRouteMiddlewareResolver;
use PhpSoftBox\Router\Middleware\RouteMiddlewareResolverInterface;
use PhpSoftBox\Router\Profiler\RouterProfilerCollector;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use RuntimeException;
use Throwable;

use function array_shift;
use function call_user_func;
use function count;
use function hrtime;
use function is_callable;

class Dispatcher
{
    private HandlerResolverInterface $handlerResolver;
    private RouteMiddlewareResolverInterface $middlewareResolver;
    private ProfilerInterface $profiler;

    public function __construct(
        ?HandlerResolverInterface $handlerResolver = null,
        ?RouteMiddlewareResolverInterface $middlewareResolver = null,
        ?ProfilerInterface $profiler = null,
        private readonly ?RouterProfilerCollector $profilerCollector = null,
    ) {
        $this->handlerResolver    = $handlerResolver ?? new DefaultHandlerResolver();
        $this->middlewareResolver = $middlewareResolver ?? new DefaultRouteMiddlewareResolver();
        $this->profiler           = $profiler ?? new NullProfiler();
    }

    public function dispatch(Route $route, ServerRequestInterface $request): ResponseInterface
    {
        $startedAt       = hrtime(true);
        $routeName       = $route->name ?? $route->path;
        $handler         = $route->handler;
        $middlewareStack = $this->profiler->span(
            'router.middleware.resolve',
            fn (): array => $this->middlewareResolver->resolve($route->middlewares),
            tags: [
                'route'             => $routeName,
                'middlewares_count' => count($route->middlewares),
            ],
            category: 'router',
        );

        $handler = new class ($handler, $middlewareStack, $this->handlerResolver) implements RequestHandlerInterface {
            private Closure|array|string $handler;
            private array $middlewareStack;
            private HandlerResolverInterface $handlerResolver;

            public function __construct(
                callable|array|string $handler,
                array $middlewareStack,
                HandlerResolverInterface $handlerResolver,
            ) {
                $this->handler         = $handler;
                $this->middlewareStack = $middlewareStack;
                $this->handlerResolver = $handlerResolver;
            }

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                if (empty($this->middlewareStack)) {
                    return $this->resolveHandler($this->handler, $request);
                }

                $middleware = array_shift($this->middlewareStack);

                return $middleware->process($request, $this);
            }

            private function resolveHandler(callable|array|string $handler, ServerRequestInterface $request): ResponseInterface
            {
                $callable = $this->handlerResolver->resolve($handler);

                if ($callable instanceof Closure) {
                    return $callable($request);
                }

                if (is_callable($callable)) {
                    return call_user_func($callable, $request);
                }

                throw new RuntimeException('Invalid handler');
            }
        };

        $span = $this->profiler->start('router.dispatch', [
            'route'             => $routeName,
            'method'            => $route->method,
            'path'              => $route->path,
            'middlewares_count' => count($route->middlewares),
        ], 'router');

        try {
            $response = $handler->handle($request);
            $span->addTag('status_code', $response->getStatusCode());

            return $response;
        } catch (Throwable $exception) {
            $span->fail($exception);

            throw $exception;
        } finally {
            $span->finish();
            $durationMs = (hrtime(true) - $startedAt) / 1_000_000;
            $this->profilerCollector?->recordDispatch(
                $route,
                $durationMs,
                isset($response) ? $response->getStatusCode() : null,
            );
        }
    }
}
