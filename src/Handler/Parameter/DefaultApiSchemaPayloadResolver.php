<?php

declare(strict_types=1);

namespace PhpSoftBox\Router\Handler\Parameter;

use PhpSoftBox\Request\Request;
use ReflectionParameter;

final readonly class DefaultApiSchemaPayloadResolver implements ApiSchemaPayloadResolverInterface
{
    public function resolve(Request $request, ReflectionParameter $parameter): array
    {
        return $request->all();
    }
}
