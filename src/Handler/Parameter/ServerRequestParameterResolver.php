<?php

declare(strict_types=1);

namespace PhpSoftBox\Router\Handler\Parameter;

use Psr\Http\Message\ServerRequestInterface;
use ReflectionNamedType;
use ReflectionParameter;

final readonly class ServerRequestParameterResolver implements HandlerParameterResolverInterface
{
    public function supports(ReflectionParameter $parameter, HandlerParameterResolutionContext $context): bool
    {
        $type = $parameter->getType();

        return $type instanceof ReflectionNamedType && $type->getName() === ServerRequestInterface::class;
    }

    public function resolve(ReflectionParameter $parameter, HandlerParameterResolutionContext $context): mixed
    {
        return $context->request;
    }
}
