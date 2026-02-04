<?php

declare(strict_types=1);

namespace PhpSoftBox\Router;

use PhpSoftBox\Router\Exception\RouteNotFoundException;
use Psr\Http\Message\ServerRequestInterface;

use function interface_exists;
use function is_a;
use function is_object;
use function ltrim;
use function preg_match;
use function preg_replace;
use function rtrim;
use function str_ends_with;
use function str_replace;
use function strtolower;
use function trim;

final readonly class UrlGenerator implements UrlGeneratorInterface
{
    private RequestContext $context;

    public function __construct(
        private RouteCollector $routeCollector,
        ?ServerRequestInterface $request = null,
        ?RequestContext $context = null,
    ) {
        if ($context !== null) {
            $this->context = $context;

            return;
        }

        $this->context = $request !== null
            ? RequestContext::fromRequest($request)
            : new RequestContext();
    }

    public function generate(string $name, array $params = [], bool $shouldAbsolute = false): string
    {
        $route = $this->routeCollector->getNamedRoutes()[$name] ?? null;
        if (!$route instanceof Route) {
            throw new RouteNotFoundException("Route with name '$name' not found");
        }

        $path = $route->path;

        foreach ($params as $key => $value) {
            $paramValue = $this->normalizeUrlParamValue($name, (string) $key, $value);
            $path       = str_replace(['{' . $key . '}', '{' . $key . '?}'], $paramValue, $path);
        }

        $path = (string) preg_replace('~/?\{[^}/]+\?}~', '', $path);

        if (preg_match('~\{[^}/]+}~', $path) === 1) {
            throw new RouteNotFoundException("Missing required parameters for route '$name'");
        }

        $path = (string) preg_replace('~//+~', '/', $path);

        if ($path !== '/' && str_ends_with($path, '/')) {
            $path = rtrim($path, '/');
        }

        $path = $this->prependBasePath($path);

        if (!$shouldAbsolute) {
            return $path;
        }

        return $this->buildAbsoluteUrl($route, $path);
    }

    public function getContext(): RequestContext
    {
        return $this->context;
    }

    private function normalizeUrlParamValue(string $routeName, string $paramName, mixed $value): string
    {
        $entityInterface = 'PhpSoftBox\\Orm\\Contracts\\EntityInterface';

        if (is_object($value) && interface_exists($entityInterface) && is_a($value, $entityInterface)) {
            /** @var object{id: callable} $value */
            $entityId = $value->id();
            if ($entityId === null) {
                throw new RouteNotFoundException(
                    "Cannot build route '$routeName': entity parameter '$paramName' has no primary key value",
                );
            }

            return (string) $entityId;
        }

        return (string) $value;
    }

    private function buildAbsoluteUrl(Route $route, string $path): string
    {
        $contextHost = trim($this->context->getHost());
        if ($contextHost !== '') {
            $scheme = $this->normalizeScheme(trim($this->context->getScheme()));
            $port   = $this->context->getPort();
            if ($scheme === 'https' && $port === 443) {
                $port = null;
            } elseif ($scheme === 'http' && $port === 443) {
                $scheme = 'https';
                $port   = null;
            }
            $isDefaultPort = $port === null
                || ($scheme === 'http' && $port === 80)
                || ($scheme === 'https' && $port === 443);

            $origin = $scheme . '://' . $contextHost;
            if (!$isDefaultPort) {
                $origin .= ':' . $port;
            }

            return $origin . $path;
        }

        $routeHost = trim((string) $route->host);
        if ($routeHost === '') {
            return $path;
        }

        if (preg_match('~^https?://~i', $routeHost) === 1) {
            return rtrim($routeHost, '/') . $path;
        }

        $scheme = $this->normalizeScheme(trim($this->context->getScheme()));
        if ($scheme === 'http' && $this->context->getPort() === 443) {
            $scheme = 'https';
        }

        return $scheme . '://' . $routeHost . $path;
    }

    private function normalizeScheme(string $scheme): string
    {
        $scheme = strtolower(trim($scheme));

        return $scheme !== '' ? $scheme : 'https';
    }

    private function prependBasePath(string $path): string
    {
        $basePath = trim($this->context->getBasePath());
        if ($basePath === '' || $basePath === '/') {
            return $path;
        }

        return '/' . trim($basePath, '/') . '/' . ltrim($path, '/');
    }
}
