<?php

declare(strict_types=1);

namespace PhpSoftBox\Router\Tests\Fixtures;

use PhpSoftBox\Orm\Contracts\EntityInterface;
use PhpSoftBox\Orm\Contracts\EntityManagerInterface;

final class DummyEntityManagerWithMetadata implements EntityManagerInterface
{
    /**
     * @param array<string, array<int|string, EntityInterface>> $entities
     */
    public function __construct(
        private array $entities,
        private DummyMetadataProvider $metadata,
        private DummyConnection $connection,
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

    public function metadata(): DummyMetadataProvider
    {
        return $this->metadata;
    }

    public function connection(): DummyConnection
    {
        return $this->connection;
    }
}
