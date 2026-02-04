<?php

declare(strict_types=1);

namespace PhpSoftBox\Orm\Contracts;

interface EntityInterface
{
    public function id(): int|string|null;
}
