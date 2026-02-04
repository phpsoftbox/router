<?php

declare(strict_types=1);

namespace PhpSoftBox\Router\Tests\Fixtures;

use PhpSoftBox\Orm\Contracts\EntityInterface;

final class DummyEntityRepository
{
    public bool $withDeletedCalled = false;

    /**
     * @param array<int|string, EntityInterface> $items
     */
    public function __construct(
        private array $items = [],
    ) {
    }

    public function findWithDeleted(int|string $id): ?EntityInterface
    {
        $this->withDeletedCalled = true;

        return $this->items[$id] ?? null;
    }
}
