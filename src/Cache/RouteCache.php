<?php

declare(strict_types=1);

namespace PhpSoftBox\Router\Cache;

use Closure;
use PhpSoftBox\Router\Exception\RouteCacheException;
use PhpSoftBox\Router\ParamTypesEnum;
use PhpSoftBox\Router\Route;
use PhpSoftBox\Router\RouteCollector;
use Psr\SimpleCache\CacheInterface;

use function count;
use function is_array;
use function is_object;
use function is_string;

final class RouteCache
{
    private const string CACHE_KEY_PREFIX = 'router.routes';
    private const string DEFAULT_ENV      = 'dev';

    public function __construct(
        private readonly CacheInterface $cache,
        private readonly ?int $ttl = null,
        private readonly ?string $environment = null,
    ) {
    }

    public function dump(RouteCollector $collector, ?string $environment = null): void
    {
        $routes = [];
        foreach ($collector->getRoutes() as $route) {
            $routes[] = $this->normalizeRoute($route);
        }

        $key = self::cacheKeyForEnvironment($environment ?? $this->environment);

        if (!$this->cache->set($key, $routes, $this->ttl)) {
            throw new RouteCacheException('Не удалось записать кеш маршрутов.');
        }
    }

    public function has(?string $environment = null): bool
    {
        $key = self::cacheKeyForEnvironment($environment ?? $this->environment);

        return $this->cache->has($key);
    }

    public function load(?string $environment = null): RouteCollector
    {
        $key  = self::cacheKeyForEnvironment($environment ?? $this->environment);
        $data = $this->cache->get($key);

        if (!is_array($data)) {
            throw new RouteCacheException('Кеш маршрутов не найден или имеет некорректный формат.');
        }

        $collector = new RouteCollector();

        foreach ($data as $route) {
            $collector->addRoute(
                method: (string) ($route['method'] ?? 'GET'),
                path: (string) ($route['path'] ?? '/'),
                handler: $route['handler'] ?? null,
                middlewares: $route['middlewares'] ?? [],
                name: $route['name'] ?? null,
                host: $route['host'] ?? null,
                defaults: $route['defaults'] ?? [],
                validators: $this->restoreValidators($route['validators'] ?? []),
            );
        }

        return $collector;
    }

    public function clear(?string $environment = null): bool
    {
        $key = self::cacheKeyForEnvironment($environment ?? $this->environment);

        return $this->cache->delete($key);
    }

    public static function cacheKeyForEnvironment(?string $environment = null): string
    {
        $env = self::normalizeEnvironment($environment);

        return self::CACHE_KEY_PREFIX . '.' . $env;
    }

    private static function normalizeEnvironment(?string $environment): string
    {
        if ($environment === null || $environment === '') {
            return self::DEFAULT_ENV;
        }

        return $environment;
    }

    /**
     * @return array<string, mixed>
     */
    private function normalizeRoute(Route $route): array
    {
        return [
            'method'      => $route->method,
            'path'        => $route->path,
            'handler'     => $this->normalizeHandler($route),
            'middlewares' => $this->normalizeMiddlewares($route),
            'name'        => $route->name,
            'host'        => $route->host,
            'defaults'    => $route->defaults,
            'validators'  => $this->normalizeValidators($route),
        ];
    }

    private function normalizeHandler(Route $route): array|string
    {
        $handler = $route->handler;

        if (is_string($handler)) {
            return $handler;
        }

        if (is_array($handler) && count($handler) === 2) {
            if (is_string($handler[0]) && is_string($handler[1])) {
                return $handler;
            }

            throw new RouteCacheException('Кеш маршрутов поддерживает только обработчики в виде [Class, method].');
        }

        if (is_object($handler) || $handler instanceof Closure) {
            throw new RouteCacheException('Кеш маршрутов не поддерживает обработчики-объекты и замыкания.');
        }

        throw new RouteCacheException('Некорректный обработчик маршрута.');
    }

    /**
     * @return list<class-string>
     */
    private function normalizeMiddlewares(Route $route): array
    {
        $middlewares = [];
        foreach ($route->middlewares as $middleware) {
            if (is_string($middleware)) {
                $middlewares[] = $middleware;
                continue;
            }

            throw new RouteCacheException('Кеш маршрутов поддерживает только middleware в виде строк (alias, группа или class-string).');
        }

        return $middlewares;
    }

    /**
     * @return array<string, string>
     */
    private function normalizeValidators(Route $route): array
    {
        $validators = [];
        foreach ($route->validators as $key => $validator) {
            if ($validator instanceof ParamTypesEnum) {
                $validators[$key] = $validator->value;
                continue;
            }

            if (is_string($validator) && ParamTypesEnum::tryFrom($validator) !== null) {
                $validators[$key] = $validator;
                continue;
            }

            throw new RouteCacheException('Кеш маршрутов не поддерживает кастомные валидаторы.');
        }

        return $validators;
    }

    /**
     * @param array<string, string> $validators
     * @return array<string, ParamTypesEnum>
     */
    private function restoreValidators(array $validators): array
    {
        $restored = [];
        foreach ($validators as $key => $validator) {
            $restored[$key] = ParamTypesEnum::from($validator);
        }

        return $restored;
    }
}
