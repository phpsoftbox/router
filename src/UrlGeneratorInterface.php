<?php

declare(strict_types=1);

namespace PhpSoftBox\Router;

interface UrlGeneratorInterface
{
    public function generate(string $name, array $params = [], bool $shouldAbsolute = false): string;

    public function getContext(): RequestContext;
}
