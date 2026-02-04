<?php

declare(strict_types=1);

namespace PhpSoftBox\Router;

readonly class RouteGroup
{
    public function __construct(
        public string $prefix,
        public array $middlewares = [],
        public ?string $host = null,
        public ?string $namePrefix = null,
    ) {
    }
}
