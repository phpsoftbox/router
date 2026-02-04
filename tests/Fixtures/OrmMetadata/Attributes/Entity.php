<?php

declare(strict_types=1);

namespace PhpSoftBox\Orm\Metadata\Attributes;

use Attribute;

#[Attribute(Attribute::TARGET_CLASS)]
final class Entity
{
    public function __construct(
        public ?string $table = null,
        public ?string $connection = null,
        public ?string $repository = null,
        public string $repositoryNamespace = 'Repository',
    ) {
    }
}
