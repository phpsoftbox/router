<?php

declare(strict_types=1);

namespace PhpSoftBox\Router\Handler;

interface HandlerResolverInterface
{
    /**
     * @param callable|array{0: object|class-string, 1: non-empty-string}|string $handler
     */
    public function resolve(callable|array|string $handler): callable;
}
