<?php

declare(strict_types=1);

namespace PhpSoftBox\Router;

readonly class RouteGroup
{
    /**
     * @param list<string> $hosts
     */
    public function __construct(
        public string $prefix,
        public array $middlewares = [],
        public array $hosts = [],
        public ?string $namePrefix = null,
        public bool $scopeBindings = false,
    ) {
    }
}
