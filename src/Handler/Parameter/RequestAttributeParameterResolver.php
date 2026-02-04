<?php

declare(strict_types=1);

namespace PhpSoftBox\Router\Handler\Parameter;

use ReflectionNamedType;
use ReflectionParameter;
use RuntimeException;

use function array_key_exists;
use function is_a;
use function is_object;

final readonly class RequestAttributeParameterResolver implements HandlerParameterResolverInterface
{
    public function supports(ReflectionParameter $parameter, HandlerParameterResolutionContext $context): bool
    {
        return $this->find($parameter, $context) !== null;
    }

    public function resolve(ReflectionParameter $parameter, HandlerParameterResolutionContext $context): mixed
    {
        $value = $this->find($parameter, $context);
        if ($value === null) {
            throw new RuntimeException('Compatible request attribute is no longer available.');
        }

        return $value;
    }

    private function find(ReflectionParameter $parameter, HandlerParameterResolutionContext $context): ?object
    {
        $type = $parameter->getType();
        if (!$type instanceof ReflectionNamedType || $type->isBuiltin()) {
            return null;
        }

        $typeName   = $type->getName();
        $attributes = $context->request->getAttributes();

        foreach ([$typeName, $parameter->getName()] as $key) {
            if (!array_key_exists($key, $attributes)) {
                continue;
            }
            $value = $attributes[$key];
            if (is_object($value) && is_a($value, $typeName)) {
                return $value;
            }
        }

        $match = null;
        foreach ($attributes as $value) {
            if (!is_object($value) || !is_a($value, $typeName)) {
                continue;
            }
            if ($match !== null && $match !== $value) {
                throw new RuntimeException('Multiple request attributes match parameter type: ' . $typeName);
            }
            $match = $value;
        }

        return $match;
    }
}
