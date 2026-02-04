<?php

declare(strict_types=1);

namespace PhpSoftBox\Router\Tests\Fixtures;

final class DummyMetadataProvider
{
    /**
     * @param array<class-string, DummyMetadata> $metadata
     */
    public function __construct(
        private array $metadata = [],
    ) {
    }

    public function for(string $class): DummyMetadata
    {
        return $this->metadata[$class];
    }
}
