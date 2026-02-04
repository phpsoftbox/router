<?php

declare(strict_types=1);

namespace PhpSoftBox\Router\Tests\Fixtures;

use PhpSoftBox\Orm\Contracts\EntityInterface;

final class DummyThroughPivot implements EntityInterface
{
    public function __construct(
        public int|string $id = 0,
    ) {
    }

    public function id(): int|string|null
    {
        return $this->id;
    }
}
