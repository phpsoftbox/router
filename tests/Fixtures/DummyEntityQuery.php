<?php

declare(strict_types=1);

namespace PhpSoftBox\Router\Tests\Fixtures;

use PhpSoftBox\Orm\Contracts\EntityInterface;

use function array_key_first;
use function trim;

final class DummyEntityQuery
{
    private ?EntityInterface $entity = null;

    /**
     * @param array<string, array<int|string, EntityInterface>> $entitiesByColumn
     */
    public function __construct(
        private array $entitiesByColumn,
    ) {
    }

    /**
     * @param array<string, mixed> $conditions
     */
    public function where(array $conditions): self
    {
        $column = array_key_first($conditions);
        if ($column === null) {
            return $this;
        }

        $column       = trim($column, '"`');
        $value        = $conditions[array_key_first($conditions)];
        $this->entity = $this->entitiesByColumn[$column][$value] ?? null;

        return $this;
    }

    public function fetchEntity(): ?EntityInterface
    {
        return $this->entity;
    }
}
