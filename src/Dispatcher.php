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
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use RuntimeException;
use Throwable;

use function array_values;
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

        // Стек не мутируется: каждый шаг получает свою копию обработчика с позицией следующего middleware.
        // Поэтому повторный $handler->handle() (retry, транзакции) снова проходит весь оставшийся стек.
        $handler = new class ($handler, array_values($middlewareStack), $this->handlerResolver) implements RequestHandlerInterface {
            private int $position = 0;

            /**
             * @param list<MiddlewareInterface> $middlewareStack
             */
            public function __construct(
                private readonly Closure|array|string $handler,
                private readonly array $middlewareStack,
                private readonly HandlerResolverInterface $handlerResolver,
            ) {
            }

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                $middleware = $this->middlewareStack[$this->position] ?? null;
                if ($middleware === null) {
                    return $this->resolveHandler($this->handler, $request);
                }

                $next           = clone $this;
                $next->position = $this->position + 1;

                return $middleware->process($request, $next);
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

            // Вне активной трассы коллектор никто не прочитает и не очистит: запись только копила бы память.
            if ($this->profiler->enabled() && $this->profiler->currentTrace() !== null) {
                $this->profilerCollector?->recordDispatch(
                    $route,
                    (hrtime(true) - $startedAt) / 1_000_000,
                    isset($response) ? $response->getStatusCode() : null,
                );
            }
        }
    }
}
