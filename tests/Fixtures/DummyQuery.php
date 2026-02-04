<?php

declare(strict_types=1);

namespace PhpSoftBox\Router\Tests\Fixtures;

use function array_merge;

final class DummyQuery
{
    private string $table = '';

    /**
     * @var array<string, scalar>
     */
    private array $params = [];

    /**
     * @param array<string, array<string, bool>> $pairs
     */
    public function __construct(
        private array $pairs = [],
    ) {
    }

    public function select(mixed $columns): self
    {
        return $this;
    }

    public function from(string $table): self
    {
        $this->table = $table;

        return $this;
    }

    /**
     * @param array<string, scalar> $params
     */
    public function where(string $expression, array $params): self
    {
        $this->params = array_merge($this->params, $params);

        return $this;
    }

    public function limit(int $limit): self
    {
        return $this;
    }

    public function fetchOne(): mixed
    {
        $ownerId   = $this->params['__psb_owner_id'] ?? null;
        $relatedId = $this->params['__psb_related_id'] ?? null;

        if (!isset($this->pairs[$this->table]) || $ownerId === null || $relatedId === null) {
            return null;
        }

        $key = $ownerId . '|' . $relatedId;

        return ($this->pairs[$this->table][$key] ?? false) ? 1 : null;
    }
}
