<?php

declare(strict_types=1);

namespace PhpSoftBox\Router;

use InvalidArgumentException;
use PhpSoftBox\Router\Exception\InvalidRouteParameterException;
use PhpSoftBox\Router\Exception\MethodNotAllowedException;
use Psr\Http\Message\ServerRequestInterface;

use function array_filter;
use function array_replace;
use function array_unique;
use function array_values;
use function ctype_digit;
use function get_debug_type;
use function in_array;
use function is_callable;
use function is_string;
use function preg_match;
use function preg_match_all;
use function preg_quote;
use function sprintf;
use function str_ends_with;
use function strlen;
use function substr;

use const ARRAY_FILTER_USE_KEY;
use const PREG_OFFSET_CAPTURE;
use const PREG_SET_ORDER;

readonly class RouteResolver
{
    public function __construct(
        private RouteCollector $routeCollector,
    ) {
    }

    /**
     * Ищет первый подходящий маршрут.
     *
     * Маршрут подходит, если совпали host, путь, валидаторы параметров и метод. Маршрут, параметр которого не прошёл
     * валидатор, считается несовпавшим: поиск продолжается по следующим маршрутам. Запрос HEAD при отсутствии
     * явного HEAD/ANY-маршрута сопоставляется с GET-маршрутом (тело ответа отбрасывает эмиттер/SAPI).
     *
     * @throws MethodNotAllowedException путь совпал, но ни один маршрут не принимает метод запроса
     * @throws InvalidRouteParameterException ничего не совпало, но хотя бы один маршрут отклонён валидатором
     */
    public function resolve(ServerRequestInterface $request): ?RouteMatch
    {
        $path   = $request->getUri()->getPath();
        $method = $request->getMethod();
        $host   = $request->getUri()->getHost();

        $allowed          = [];
        $headFallback     = null;
        $invalidParameter = null;

        foreach ($this->routeCollector->getRoutes() as $route) {
            if (!$this->isHostMatch($route, $host)) {
                continue;
            }

            $params = $this->matchPath($route->path, $path, $request);
            if ($params === null) {
                continue;
            }

            $invalidKey = $this->findInvalidParam($route, $params);
            if ($invalidKey !== null) {
                $invalidParameter ??= new InvalidRouteParameterException(sprintf(
                    'Invalid parameter: %s. This may indicate an invalid value or a missing/misordered route for path "%s".',
                    $invalidKey,
                    $route->path,
                ));
                continue;
            }

            if (!$this->isMethodMatch($route, $method)) {
                if ($method === 'HEAD' && $route->method === 'GET') {
                    // Явный HEAD/ANY-маршрут ниже по списку приоритетнее GET.
                    $headFallback ??= $this->createMatch($route, $params);
                    continue;
                }

                if ($route->method !== 'ANY') {
                    $allowed[] = $route->method;
                }
                continue;
            }

            return $this->createMatch($route, $params);
        }

        if ($headFallback !== null) {
            return $headFallback;
        }

        if ($allowed !== []) {
            if (in_array('GET', $allowed, true)) {
                $allowed[] = 'HEAD';
            }

            throw new MethodNotAllowedException(array_values(array_unique($allowed)));
        }

        if ($invalidParameter !== null) {
            throw $invalidParameter;
        }

        return null;
    }

    /**
     * @param array<string, string> $params
     */
    private function createMatch(Route $route, array $params): RouteMatch
    {
        return new RouteMatch($route, array_replace($route->defaults, $params));
    }

    private function isMethodMatch(Route $route, string $method): bool
    {
        return $route->method === $method || $route->method === 'ANY';
    }

    private function isHostMatch(Route $route, string $host): bool
    {
        return RouteHost::matches($route->hosts, $host);
    }

    private function matchPath(string $routePath, string $requestPath, ServerRequestInterface $request): ?array
    {
        static $patternCache = [];

        if (!isset($patternCache[$routePath])) {
            $patternCache[$routePath] = $this->compileRoutePattern($routePath);
        }

        $routePattern = $patternCache[$routePath];

        if (preg_match($routePattern, $requestPath, $matches)) {
            return array_filter($matches, function ($key) {
                return is_string($key);
            }, ARRAY_FILTER_USE_KEY);
        }

        return null;
    }

    private function compileRoutePattern(string $routePath): string
    {
        $regex  = '';
        $offset = 0;

        preg_match_all(
            '#\{([A-Za-z_][A-Za-z0-9_]*)(\*)?(\?)?}#',
            $routePath,
            $matches,
            PREG_SET_ORDER | PREG_OFFSET_CAPTURE,
        );

        foreach ($matches as $match) {
            $placeholder = $match[0][0];
            $position    = $match[0][1];
            $name        = $match[1][0];
            $isWildcard  = ($match[2][0] ?? '') === '*';
            $isOptional  = ($match[3][0] ?? '') === '?';

            if ($isWildcard) {
                $this->assertWildcardIsLastSegment($routePath, $placeholder, $position);
            }

            $literal = substr($routePath, $offset, $position - $offset);
            $prefix  = '';
            if ($isOptional && str_ends_with($literal, '/')) {
                $literal = substr($literal, 0, -1);
                $prefix  = '/';
            }

            $regex .= preg_quote($literal, '#');

            $valuePattern = $isWildcard ? '.+' : '[^/]+';
            if ($isOptional) {
                $regex .= '(?:' . preg_quote($prefix, '#') . '(?P<' . $name . '>' . $valuePattern . '))?';
            } else {
                $regex .= '(?P<' . $name . '>' . $valuePattern . ')';
            }

            $offset = $position + strlen($placeholder);
        }

        $regex .= preg_quote(substr($routePath, $offset), '#');

        return '#^' . $regex . '$#';
    }

    private function assertWildcardIsLastSegment(string $routePath, string $placeholder, int $position): void
    {
        $placeholderEnd = $position + strlen($placeholder);
        $previousChar   = $position > 0 ? $routePath[$position - 1] : '';

        if ($placeholderEnd === strlen($routePath) && ($position === 0 || $previousChar === '/')) {
            return;
        }

        throw new InvalidArgumentException(sprintf(
            'Wildcard route parameter must be the last path segment: %s',
            $routePath,
        ));
    }

    /**
     * @param callable|ParamTypesEnum|string $validator строка — значение ParamTypesEnum (например, из кеша маршрутов)
     */
    private function validateParam(string $value, mixed $validator, string $key, Route $route): bool
    {
        if (is_string($validator)) {
            $validator = ParamTypesEnum::tryFrom($validator) ?? $validator;
        }

        if ($validator instanceof ParamTypesEnum) {
            return match ($validator) {
                ParamTypesEnum::INT    => ctype_digit($value),
                ParamTypesEnum::STRING => true,
            };
        }

        if (is_callable($validator)) {
            return (bool) $validator($value);
        }

        throw new InvalidArgumentException(sprintf(
            'Invalid validator for parameter "%s" of route "%s": expected %s value or callable, got %s.',
            $key,
            $route->path,
            ParamTypesEnum::class,
            get_debug_type($validator),
        ));
    }

    /**
     * Возвращает имя первого параметра, не прошедшего валидатор, или null.
     *
     * @param array<string, string> $params
     */
    private function findInvalidParam(Route $route, array $params): ?string
    {
        foreach ($params as $key => $value) {
            if (!isset($route->validators[$key])) {
                continue;
            }

            if (!$this->validateParam($value, $route->validators[$key], $key, $route)) {
                return $key;
            }
        }

        return null;
    }
}
