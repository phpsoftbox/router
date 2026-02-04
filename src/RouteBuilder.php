<?php

declare(strict_types=1);

namespace PhpSoftBox\Router;

use Closure;
use Psr\Http\Server\MiddlewareInterface;
use RuntimeException;

use function array_merge;
use function trim;

final class RouteBuilder
{
    /**
     * @param callable(callable(Route):Route):void $mutateRoute
     * @param callable(?string):?string $qualifyRouteName
     */
    public function __construct(
        private readonly Closure $mutateRoute,
        private readonly Closure $qualifyRouteName,
    ) {
    }

    public function middleware(MiddlewareInterface|string $middleware): self
    {
        return $this->middlewares([$middleware]);
    }

    /**
     * @param list<MiddlewareInterface|string> $middlewares
     */
    public function middlewares(array $middlewares): self
    {
        if ($middlewares === []) {
            return $this;
        }

        ($this->mutateRoute)(static function (Route $route) use ($middlewares): Route {
            return new Route(
                method: $route->method,
                path: $route->path,
                handler: $route->handler,
                middlewares: array_merge($route->middlewares, $middlewares),
                name: $route->name,
                host: $route->host,
                defaults: $route->defaults,
                validators: $route->validators,
                scopeBindings: $route->scopeBindings,
            );
        });

        return $this;
    }

    public function name(string $name): self
    {
        $name = ($this->qualifyRouteName)($name);

        ($this->mutateRoute)(static function (Route $route) use ($name): Route {
            return new Route(
                method: $route->method,
                path: $route->path,
                handler: $route->handler,
                middlewares: $route->middlewares,
                name: $name,
                host: $route->host,
                defaults: $route->defaults,
                validators: $route->validators,
                scopeBindings: $route->scopeBindings,
            );
        });

        return $this;
    }

    public function host(?string $host): self
    {
        $host = $host !== null ? trim($host) : null;
        if ($host === '') {
            $host = null;
        }

        ($this->mutateRoute)(static function (Route $route) use ($host): Route {
            return new Route(
                method: $route->method,
                path: $route->path,
                handler: $route->handler,
                middlewares: $route->middlewares,
                name: $route->name,
                host: $host,
                defaults: $route->defaults,
                validators: $route->validators,
                scopeBindings: $route->scopeBindings,
            );
        });

        return $this;
    }

    public function defaults(array $defaults): self
    {
        ($this->mutateRoute)(static function (Route $route) use ($defaults): Route {
            return new Route(
                method: $route->method,
                path: $route->path,
                handler: $route->handler,
                middlewares: $route->middlewares,
                name: $route->name,
                host: $route->host,
                defaults: $defaults,
                validators: $route->validators,
                scopeBindings: $route->scopeBindings,
            );
        });

        return $this;
    }

    public function default(string $name, mixed $value): self
    {
        if ($name === '') {
            throw new RuntimeException('Default parameter name must not be empty.');
        }

        ($this->mutateRoute)(static function (Route $route) use ($name, $value): Route {
            $defaults        = $route->defaults;
            $defaults[$name] = $value;

            return new Route(
                method: $route->method,
                path: $route->path,
                handler: $route->handler,
                middlewares: $route->middlewares,
                name: $route->name,
                host: $route->host,
                defaults: $defaults,
                validators: $route->validators,
                scopeBindings: $route->scopeBindings,
            );
        });

        return $this;
    }

    public function validators(array $validators): self
    {
        ($this->mutateRoute)(static function (Route $route) use ($validators): Route {
            return new Route(
                method: $route->method,
                path: $route->path,
                handler: $route->handler,
                middlewares: $route->middlewares,
                name: $route->name,
                host: $route->host,
                defaults: $route->defaults,
                validators: $validators,
                scopeBindings: $route->scopeBindings,
            );
        });

        return $this;
    }

    public function where(string $name, mixed $validator): self
    {
        if ($name === '') {
            throw new RuntimeException('Validator parameter name must not be empty.');
        }

        ($this->mutateRoute)(static function (Route $route) use ($name, $validator): Route {
            $validators        = $route->validators;
            $validators[$name] = $validator;

            return new Route(
                method: $route->method,
                path: $route->path,
                handler: $route->handler,
                middlewares: $route->middlewares,
                name: $route->name,
                host: $route->host,
                defaults: $route->defaults,
                validators: $validators,
                scopeBindings: $route->scopeBindings,
            );
        });

        return $this;
    }

    public function scopeBindings(bool $enabled = true): self
    {
        ($this->mutateRoute)(static function (Route $route) use ($enabled): Route {
            return new Route(
                method: $route->method,
                path: $route->path,
                handler: $route->handler,
                middlewares: $route->middlewares,
                name: $route->name,
                host: $route->host,
                defaults: $route->defaults,
                validators: $route->validators,
                scopeBindings: $enabled,
            );
        });

        return $this;
    }
}
