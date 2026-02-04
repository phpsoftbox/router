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
