<?php

declare(strict_types=1);

namespace PhpSoftBox\Router\Tests\Fixtures;

final class DummyMetadata
{
    /**
     * @param array<string, DummyColumnMeta> $columns
     * @param list<object> $relations
     */
    public function __construct(
        public string $table,
        public array $columns = [],
        public array $relations = [],
    ) {
    }
}
