<?php

declare(strict_types=1);

namespace PhpSoftBox\Router\Tests\Fixtures;

use PhpSoftBox\Orm\Contracts\EntityInterface;

final class DummyScopedCompany implements EntityInterface
{
    public function __construct(
        public int|string $id,
        public int|string $userId,
    ) {
    }

    public function id(): int|string|null
    {
        return $this->id;
    }
}
