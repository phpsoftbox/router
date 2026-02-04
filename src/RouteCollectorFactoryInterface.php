<?php

declare(strict_types=1);

namespace PhpSoftBox\Router;

interface RouteCollectorFactoryInterface
{
    public function create(): RouteCollector;
}
