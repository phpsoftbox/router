<?php

declare(strict_types=1);

namespace PhpSoftBox\Router\Tests\Fixtures;

use PhpSoftBox\Orm\Contracts\EntityInterface;
use PhpSoftBox\Orm\Metadata\Attributes\Entity;

#[Entity(table: 'users', connection: 'tenant')]
final class TenantBoundEntity implements EntityInterface
{
    public function __construct(
        private readonly int $id,
    ) {
    }

    public function id(): int|string|null
    {
        return $this->id;
    }
}
