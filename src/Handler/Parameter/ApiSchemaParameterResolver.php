<?php

declare(strict_types=1);

namespace PhpSoftBox\Router\Handler\Parameter;

use PhpSoftBox\Request\ApiSchema;
use PhpSoftBox\Validator\ValidatorInterface;
use ReflectionClass;
use ReflectionNamedType;
use ReflectionParameter;
use RuntimeException;

use function class_exists;
use function is_callable;
use function is_subclass_of;

final readonly class ApiSchemaParameterResolver implements HandlerParameterResolverInterface
{
    public function supports(ReflectionParameter $parameter, HandlerParameterResolutionContext $context): bool
    {
        $type = $parameter->getType();

        return $type instanceof ReflectionNamedType
            && class_exists(ApiSchema::class)
            && class_exists($type->getName())
            && is_subclass_of($type->getName(), ApiSchema::class);
    }

    public function resolve(ReflectionParameter $parameter, HandlerParameterResolutionContext $context): mixed
    {
        /** @var class-string<ApiSchema> $class */
        $class           = $parameter->getType()->getName();
        $request         = $context->applicationRequest();
        $payloadResolver = $this->payloadResolver($context);
        $payload         = $payloadResolver->resolve($request, $parameter);
        $validator       = $context->validator();
        $parameters      = ['payload' => $payload, 'validator' => $validator];
        $constructor     = new ReflectionClass($class)->getConstructor();

        if ($constructor !== null) {
            foreach ($constructor->getParameters() as $constructorParameter) {
                $type = $constructorParameter->getType();
                if ($type instanceof ReflectionNamedType && $type->getName() === ValidatorInterface::class) {
                    $parameters[$constructorParameter->getName()] = $validator;
                }
            }
        }

        if (is_callable([$context->container, 'make'])) {
            $schema = $context->container->make($class, $parameters);
        } else {
            $schema = new $class($payload, $validator);
        }

        if (!$schema instanceof ApiSchema) {
            throw new RuntimeException('Container must create an ApiSchema instance for: ' . $class);
        }

        $schema->validate();

        return $schema;
    }

    private function payloadResolver(HandlerParameterResolutionContext $context): ApiSchemaPayloadResolverInterface
    {
        if ($context->container->has(ApiSchemaPayloadResolverInterface::class)) {
            $resolver = $context->container->get(ApiSchemaPayloadResolverInterface::class);
            if (!$resolver instanceof ApiSchemaPayloadResolverInterface) {
                throw new RuntimeException(
                    'ApiSchemaPayloadResolverInterface binding must resolve a compatible instance.',
                );
            }

            return $resolver;
        }

        return new DefaultApiSchemaPayloadResolver();
    }
}
