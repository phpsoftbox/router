<?php

declare(strict_types=1);

namespace PhpSoftBox\Router\Attributes;

use Attribute;

#[Attribute(Attribute::TARGET_PARAMETER)]
final readonly class WithDeleted
{
    public function __construct(
        public bool $value = true,
    ) {
    }
}
