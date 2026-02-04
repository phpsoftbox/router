<?php

declare(strict_types=1);

namespace PhpSoftBox\Router\Tests\Fixtures;

use PhpSoftBox\Orm\Contracts\EntityAwareEntityManagerRegistryInterface;
use PhpSoftBox\Orm\Contracts\EntityManagerInterface;

final class DummyEntityManagerRegistry implements EntityAwareEntityManagerRegistryInterface
{
    public ?string $requestedConnectionName = null;
    public ?string $requestedEntityClass    = null;

    /**
     * @param array<string, EntityManagerInterface> $entityManagersByConnection
     */
    public function __construct(
        private readonly EntityManagerInterface $defaultEntityManager,
        private readonly array $entityManagersByConnection = [],
    ) {
    }

    public function default(bool $write = true): EntityManagerInterface
    {
        return $this->defaultEntityManager;
    }

    public function forConnection(string $connectionName, bool $write = true): EntityManagerInterface
    {
        $this->requestedConnectionName = $connectionName;

        return $this->entityManagersByConnection[$connectionName] ?? $this->defaultEntityManager;
    }

    public function forEntity(string $entityClass, bool $write = true): EntityManagerInterface
    {
        $this->requestedEntityClass = $entityClass;

        return $this->entityManagersByConnection[$entityClass] ?? $this->defaultEntityManager;
    }
}
