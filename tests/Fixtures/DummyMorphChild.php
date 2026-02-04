<?php

declare(strict_types=1);

namespace PhpSoftBox\Router\Tests\Fixtures;

use PhpSoftBox\Orm\Contracts\EntityInterface;

final class DummyMorphChild implements EntityInterface
{
    public function __construct(
        public int|string $id,
        public string $commentable_type,
        public int|string $commentable_id,
    ) {
    }

    public function id(): int|string|null
    {
        return $this->id;
    }
}
