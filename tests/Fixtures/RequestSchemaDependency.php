<?php

declare(strict_types=1);

namespace PhpSoftBox\Router\Tests\Fixtures;

final class RequestSchemaDependency
{
    public function source(): string
    {
        return 'container';
    }
}
