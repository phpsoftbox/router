<?php

declare(strict_types=1);

namespace PhpSoftBox\Orm\Contracts;

interface EntityManagerRegistryInterface
{
    public function default(bool $write = true): EntityManagerInterface;

    public function forConnection(string $connectionName, bool $write = true): EntityManagerInterface;
}
