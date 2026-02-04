<?php

declare(strict_types=1);

namespace PhpSoftBox\Router;

use Closure;
use PhpSoftBox\Router\Handler\DefaultHandlerResolver;
use PhpSoftBox\Router\Handler\HandlerResolverInterface;
use PhpSoftBox\Router\Middleware\DefaultRouteMiddlewareResolver;
use PhpSoftBox\Router\Middleware\RouteMiddlewareResolverInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use RuntimeException;

use function array_shift;
use function call_user_func;
use function is_callable;

class Dispatcher
{
    private HandlerResolverInterface $handlerResolver;
    private RouteMiddlewareResolverInterface $middlewareResolver;

    public function __construct(
        ?HandlerResolverInterface $handlerResolver = null,
        ?RouteMiddlewareResolverInterface $middlewareResolver = null,
    ) {
        $this->handlerResolver    = $handlerResolver ?? new DefaultHandlerResolver();
        $this->middlewareResolver = $middlewareResolver ?? new DefaultRouteMiddlewareResolver();
    }

    public function dispatch(Route $route, ServerRequestInterface $request): ResponseInterface
    {
        $handler         = $route->handler;
        $middlewareStack = $this->middlewareResolver->resolve($route->middlewares);

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

        return $handler->handle($request);
    }
}
