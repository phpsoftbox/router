<?php

declare(strict_types=1);

namespace PhpSoftBox\Router\Tests\Fixtures;

final readonly class RequestScopedValue
{
    public string $value;

    public function __construct(string $value)
    {
        $this->value = $value;
    }
}
