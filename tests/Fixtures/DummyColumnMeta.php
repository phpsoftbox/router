<?php

declare(strict_types=1);

namespace PhpSoftBox\Router\Tests\Fixtures;

final class DummyColumnMeta
{
    public function __construct(
        public string $column,
    ) {
    }
}
