<?php

declare(strict_types=1);

namespace PhpSoftBox\Router\Middleware;

use Psr\Http\Server\MiddlewareInterface;

interface RouteMiddlewareResolverInterface
{
    /**
     * @param array<MiddlewareInterface|string> $middlewares
     * @return list<MiddlewareInterface>
     */
    public function resolve(array $middlewares): array;
}
