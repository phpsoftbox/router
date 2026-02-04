<?php

declare(strict_types=1);

namespace PhpSoftBox\Router\Handler;

interface HandlerResolverInterface
{
    public function resolve(callable|array|string $handler): callable;
}
