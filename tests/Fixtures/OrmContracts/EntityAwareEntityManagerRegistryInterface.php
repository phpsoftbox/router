<?php

declare(strict_types=1);

namespace PhpSoftBox\Orm\Contracts;

interface EntityAwareEntityManagerRegistryInterface extends EntityManagerRegistryInterface
{
    /**
     * @param class-string $entityClass
     */
    public function forEntity(string $entityClass, bool $write = true): EntityManagerInterface;
}
