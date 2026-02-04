<?php

declare(strict_types=1);

namespace PhpSoftBox\Router;

use PhpSoftBox\Router\Exception\InvalidRouteParameterException;
use PhpSoftBox\Router\Exception\MethodNotAllowedException;
use Psr\Http\Message\ServerRequestInterface;

use function array_filter;
use function array_replace;
use function array_unique;
use function array_values;
use function ctype_digit;
use function is_string;
use function preg_match;
use function preg_replace;
use function sprintf;

use const ARRAY_FILTER_USE_KEY;

readonly class RouteResolver
{
    public function __construct(
        private RouteCollector $routeCollector,
    ) {
    }

    public function resolve(ServerRequestInterface $request): ?RouteMatch
    {
        $path   = $request->getUri()->getPath();
        $method = $request->getMethod();
        $host   = $request->getUri()->getHost();

        $allowed = [];

        foreach ($this->routeCollector->getRoutes() as $route) {
            if (!$this->isHostMatch($route, $host)) {
                continue;
            }

            $params = $this->matchPath($route->path, $path, $request);
            if ($params === null) {
                continue;
            }

            if (!$this->isMethodMatch($route, $method)) {
                if ($route->method !== 'ANY') {
                    $allowed[] = $route->method;
                }
                continue;
            }

            $this->validateParams($route, $params);

            $params = array_replace($route->defaults, $params);

            return new RouteMatch($route, $params);
        }

        if ($allowed !== []) {
            throw new MethodNotAllowedException(array_values(array_unique($allowed)));
        }

        return null;
    }

    private function isMethodMatch(Route $route, string $method): bool
    {
        return $route->method === $method || $route->method === 'ANY';
    }

    private function isHostMatch(Route $route, string $host): bool
    {
        return $route->host === null || $route->host === $host;
    }

    private function matchPath(string $routePath, string $requestPath, ServerRequestInterface $request): ?array
    {
        static $patternCache = [];

        if (!isset($patternCache[$routePath])) {
            $pattern = $routePath;
            // Опциональные параметры с предшествующим слэшем: '/{param?}' => '(?:/(?P<param>[^/]+))?'
            $pattern = preg_replace('#/\{([^}]+)\?}#', '(?:/(?P<$1>[^/]+))?', $pattern);
            // Оставшиеся опциональные параметры без слэша: '{param?}' => '(?P<param>[^/]+)?'
            $pattern = preg_replace('#\{([^}]+)\?}#', '(?P<$1>[^/]+)?', $pattern);
            // Обязательные параметры: '{param}' => '(?P<param>[^/]+)'
            $pattern = preg_replace('#\{([^}]+)}#', '(?P<$1>[^/]+)', $pattern);

            $patternCache[$routePath] = '#^' . $pattern . '$#';
        }

        $routePattern = $patternCache[$routePath];

        if (preg_match($routePattern, $requestPath, $matches)) {
            return array_filter($matches, function ($key) {
                return is_string($key);
            }, ARRAY_FILTER_USE_KEY);
        }

        return null;
    }

    private function validateParam(mixed $value, callable|ParamTypesEnum $validator): bool
    {
        if ($validator instanceof ParamTypesEnum) {
            return match ($validator) {
                ParamTypesEnum::INT    => ctype_digit($value),
                ParamTypesEnum::STRING => is_string($value),
                default                => true,
            };
        }

        // Кастомный валидатор
        return $validator($value);
    }

    /**
     * @param array<string, string> $params
     */
    private function validateParams(Route $route, array $params): void
    {
        foreach ($params as $key => $value) {
            if (!isset($route->validators[$key])) {
                continue;
            }

            $validator = $route->validators[$key];
            if (!$this->validateParam($value, $validator)) {
                throw new InvalidRouteParameterException(sprintf(
                    'Invalid parameter: %s. This may indicate an invalid value or a missing/misordered route for path "%s".',
                    $key,
                    $route->path,
                ));
            }
        }
    }
}
