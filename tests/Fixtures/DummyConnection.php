<?php

declare(strict_types=1);

namespace PhpSoftBox\Router\Tests\Fixtures;

final class DummyConnection
{
    /**
     * @param array<string, array<string, bool>> $pairs
     */
    public function __construct(
        private array $pairs = [],
    ) {
    }

    public function query(): DummyQuery
    {
        return new DummyQuery($this->pairs);
    }
}
