<?php

declare(strict_types=1);

namespace PhpSoftBox\Router\Handler\Parameter;

use PhpSoftBox\Request\Request;
use PhpSoftBox\Request\RequestSchema;
use ReflectionClass;
use ReflectionNamedType;
use ReflectionParameter;
use RuntimeException;

use function class_exists;
use function is_callable;
use function is_subclass_of;

final readonly class RequestSchemaParameterResolver implements HandlerParameterResolverInterface
{
    public function supports(ReflectionParameter $parameter, HandlerParameterResolutionContext $context): bool
    {
        $type = $parameter->getType();

        return $type instanceof ReflectionNamedType
            && class_exists(RequestSchema::class)
            && class_exists($type->getName())
            && is_subclass_of($type->getName(), RequestSchema::class);
    }

    public function resolve(ReflectionParameter $parameter, HandlerParameterResolutionContext $context): mixed
    {
        /** @var class-string<RequestSchema> $class */
        $class       = $parameter->getType()->getName();
        $appRequest  = $context->applicationRequest();
        $parameters  = ['request' => $appRequest];
        $constructor = new ReflectionClass($class)->getConstructor();

        if ($constructor !== null) {
            foreach ($constructor->getParameters() as $constructorParameter) {
                $type = $constructorParameter->getType();
                if ($type instanceof ReflectionNamedType && $type->getName() === Request::class) {
                    $parameters[$constructorParameter->getName()] = $appRequest;
                }
            }
        }

        if (is_callable([$context->container, 'make'])) {
            $schema = $context->container->make($class, $parameters);
        } else {
            $schema = new $class($appRequest);
        }

        if (!$schema instanceof RequestSchema) {
            throw new RuntimeException('Container must create a RequestSchema instance for: ' . $class);
        }

        $schema->validate();

        return $schema;
    }
}
