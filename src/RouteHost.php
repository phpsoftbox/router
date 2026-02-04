<?php

declare(strict_types=1);

namespace PhpSoftBox\Router;

use InvalidArgumentException;

use function is_string;
use function parse_url;
use function preg_match;
use function strtolower;
use function trim;

use const PHP_URL_HOST;

final class RouteHost
{
    /**
     * @param string|list<string>|null $host
     * @return list<string>
     */
    public static function normalize(string|array|null $host): array
    {
        if ($host === null) {
            return [];
        }

        return self::normalizeCandidates(is_string($host) ? [$host] : $host);
    }

    /**
     * @param list<string> $hosts
     */
    public static function canonical(array $hosts): ?string
    {
        return $hosts[0] ?? null;
    }

    /**
     * @param list<string> $routeHosts
     */
    public static function matches(array $routeHosts, string $requestHost): bool
    {
        if ($routeHosts === []) {
            return true;
        }

        return self::contains($routeHosts, $requestHost);
    }

    /**
     * @param list<string> $routeHosts
     */
    public static function contains(array $routeHosts, string $requestHost): bool
    {
        $requestHost = strtolower(trim($requestHost));
        if ($requestHost === '') {
            return false;
        }

        foreach ($routeHosts as $candidate) {
            if (self::normalizeForMatch($candidate) === $requestHost) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<array-key, mixed> $hosts
     * @return list<string>
     */
    private static function normalizeCandidates(array $hosts): array
    {
        $normalized = [];
        foreach ($hosts as $host) {
            if (!is_string($host)) {
                throw new InvalidArgumentException('Route host must be a string.');
            }

            $host = trim($host);
            if ($host === '') {
                continue;
            }

            $normalized[] = $host;
        }

        return $normalized;
    }

    private static function normalizeForMatch(string $host): string
    {
        $host = trim($host);
        if (preg_match('~^https?://~i', $host) === 1) {
            $parsedHost = parse_url($host, PHP_URL_HOST);
            if (is_string($parsedHost) && $parsedHost !== '') {
                $host = $parsedHost;
            }
        }

        return strtolower($host);
    }
}
