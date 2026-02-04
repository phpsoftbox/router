<?php

declare(strict_types=1);

namespace PhpSoftBox\Orm\Metadata;

interface MetadataProviderInterface
{
    public function for(string $entityClass): object;
}
