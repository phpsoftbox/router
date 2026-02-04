<?php

declare(strict_types=1);

namespace PhpSoftBox\Router\Handler\Parameter;

use PhpSoftBox\Request\Request;
use ReflectionParameter;

interface ApiSchemaPayloadResolverInterface
{
    /** @return array<string, mixed> */
    public function resolve(Request $request, ReflectionParameter $parameter): array;
}
