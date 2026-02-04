<?php

declare(strict_types=1);

namespace PhpSoftBox\Router\Handler\Parameter;

use PhpSoftBox\Request\Request;
use ReflectionNamedType;
use ReflectionParameter;

final readonly class ApplicationRequestParameterResolver implements HandlerParameterResolverInterface
{
    public function supports(ReflectionParameter $parameter, HandlerParameterResolutionContext $context): bool
    {
        $type = $parameter->getType();

        return $type instanceof ReflectionNamedType && $type->getName() === Request::class;
    }

    public function resolve(ReflectionParameter $parameter, HandlerParameterResolutionContext $context): mixed
    {
        return $context->applicationRequest();
    }
}
