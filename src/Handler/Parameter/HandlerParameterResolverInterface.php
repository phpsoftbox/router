<?php

declare(strict_types=1);

namespace PhpSoftBox\Router\Handler\Parameter;

use ReflectionParameter;

interface HandlerParameterResolverInterface
{
    public function supports(ReflectionParameter $parameter, HandlerParameterResolutionContext $context): bool;

    public function resolve(ReflectionParameter $parameter, HandlerParameterResolutionContext $context): mixed;
}
