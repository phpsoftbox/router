<?php

declare(strict_types=1);

namespace PhpSoftBox\Router\Tests\Fixtures;

use PhpSoftBox\Orm\Contracts\EntityInterface;

final class DummyEntity implements EntityInterface
{
    public function __construct(
        private int|string $entityId,
    ) {
    }

    public function id(): int|string|null
    {
        return $this->entityId;
    }
}
