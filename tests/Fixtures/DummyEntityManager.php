<?php

declare(strict_types=1);

namespace PhpSoftBox\Router\Tests\Fixtures;

use PhpSoftBox\Orm\Contracts\EntityInterface;
use PhpSoftBox\Orm\Contracts\EntityManagerInterface;

final class DummyEntityManager implements EntityManagerInterface
{
    /**
     * @param array<string, array<int|string, EntityInterface>> $entities
     */
    public function __construct(
        private array $entities = [],
        private ?DummyEntityRepository $repository = null,
    ) {
    }

    public function find(string $entityClass, int|string $id): ?EntityInterface
    {
        return $this->entities[$entityClass][$id] ?? null;
    }

    public function repository(string $entityClass): object
    {
        return $this->repository ?? new DummyEntityRepository($this->entities[$entityClass] ?? []);
    }
}
