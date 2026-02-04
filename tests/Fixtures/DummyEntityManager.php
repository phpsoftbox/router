<?php

declare(strict_types=1);

namespace PhpSoftBox\Router\Tests\Fixtures;

use PhpSoftBox\Orm\Contracts\EntityInterface;
use PhpSoftBox\Orm\Contracts\EntityManagerInterface;

final class DummyEntityManager implements EntityManagerInterface
{
    /**
     * @var list<array{entity: object, relations: list<string>}>
     */
    public array $loadCalls = [];

    /**
     * @var list<array{entityClass: string, withDeleted: bool}>
     */
    public array $queryForCalls = [];

    private DummyConnection $connection;
    private DummyMetadataProvider $metadataProvider;

    /**
     * @param array<string, array<int|string, EntityInterface>> $entities
     * @param array<string, array<string, array<int|string, EntityInterface>>> $entitiesByColumn
     */
    public function __construct(
        private array $entities = [],
        private ?DummyEntityRepository $repository = null,
        private array $entitiesByColumn = [],
        ?DummyConnection $connection = null,
        ?DummyMetadataProvider $metadataProvider = null,
    ) {
        $this->connection       = $connection ?? new DummyConnection();
        $this->metadataProvider = $metadataProvider ?? new DummyMetadataProvider();
    }

    public function find(string $entityClass, int|string $id): ?EntityInterface
    {
        return $this->entities[$entityClass][$id] ?? null;
    }

    public function repository(string $entityClass): object
    {
        return $this->repository ?? new DummyEntityRepository($this->entities[$entityClass] ?? []);
    }

    public function connection(): DummyConnection
    {
        return $this->connection;
    }

    public function metadataProvider(): DummyMetadataProvider
    {
        return $this->metadataProvider;
    }

    public function queryFor(string $entityClass, bool $withDeleted = false): DummyEntityQuery
    {
        $this->queryForCalls[] = [
            'entityClass' => $entityClass,
            'withDeleted' => $withDeleted,
        ];

        return new DummyEntityQuery($this->entitiesByColumn[$entityClass] ?? []);
    }

    /**
     * @param list<string> $relations
     */
    public function load(object $entity, array $relations): void
    {
        $this->loadCalls[] = [
            'entity'    => $entity,
            'relations' => $relations,
        ];
    }
}
