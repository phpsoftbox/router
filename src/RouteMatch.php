<?php

declare(strict_types=1);

namespace PhpSoftBox\Router;

final readonly class RouteMatch
{
    /**
     * @param array<string, mixed> $params
     */
    public function __construct(
        public Route $route,
        public array $params = [],
    ) {
    }
}
