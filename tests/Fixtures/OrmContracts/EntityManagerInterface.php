<?php

declare(strict_types=1);

namespace PhpSoftBox\Orm\Contracts;

use PhpSoftBox\Orm\Metadata\MetadataProviderInterface;

interface EntityManagerInterface
{
    public function find(string $entityClass, int|string $id): ?EntityInterface;

    public function repository(string $entityClass): object;

    public function metadataProvider(): MetadataProviderInterface;
}
