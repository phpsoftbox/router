<?php

declare(strict_types=1);

namespace PhpSoftBox\Router\Handler;

use Closure;
use PhpSoftBox\Request\Request;
use PhpSoftBox\Request\RequestSchema;
use PhpSoftBox\Router\Attributes\ResolveEntity;
use PhpSoftBox\Router\Attributes\WithDeleted;
use PhpSoftBox\Router\Binding\ScopedBindingsResolverInterface;
use PhpSoftBox\Router\Exception\InvalidRouteParameterException;
use PhpSoftBox\Validator\ValidatorInterface;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\ContainerInterface;
use Psr\Container\NotFoundExceptionInterface;
use Psr\Http\Message\ServerRequestInterface;
use Ramsey\Uuid\UuidInterface;
use ReflectionClass;
use ReflectionFunction;
use ReflectionFunctionAbstract;
use ReflectionMethod;
use ReflectionNamedType;
use ReflectionParameter;
use RuntimeException;
use Throwable;

use function array_key_exists;
use function array_key_last;
use function array_values;
use function class_exists;
use function count;
use function ctype_digit;
use function in_array;
use function interface_exists;
use function is_a;
use function is_array;
use function is_callable;
use function is_int;
use function is_object;
use function is_string;
use function is_subclass_of;
use function method_exists;
use function property_exists;

final class ContainerHandlerResolver implements HandlerResolverInterface
{
    public function __construct(
        private readonly ContainerInterface $container,
    ) {
    }

    public function resolve(callable|array|string $handler): callable
    {
        if (is_string($handler) && class_exists($handler)) {
            $instance = $this->resolveInstance($handler);
            if (method_exists($instance, '__invoke')) {
                return $this->wrapCallable([$instance, '__invoke']);
            }
        }

        if (is_array($handler) && count($handler) === 2) {
            [$class, $method] = $handler;

            if (is_object($class) && is_callable([$class, $method])) {
                return $this->wrapCallable([$class, $method]);
            }

            if (is_string($class) && class_exists($class)) {
                $controller = $this->resolveInstance($class);

                if (is_callable([$controller, $method])) {
                    return $this->wrapCallable([$controller, $method]);
                }
            }
        }

        if (is_callable($handler)) {
            return $this->wrapCallable($handler);
        }

        throw new RuntimeException('Invalid handler');
    }

    private function resolveInstance(string $class): object
    {
        try {
            return $this->container->get($class);
        } catch (Throwable $exception) {
            if ($exception instanceof NotFoundExceptionInterface && $this->canInstantiateWithoutArguments($class)) {
                return new $class();
            }

            if ($exception instanceof ContainerExceptionInterface || $exception instanceof NotFoundExceptionInterface) {
                throw new RuntimeException(
                    'Failed to resolve handler class from container: ' . $class . '. ' . $exception->getMessage(),
                    0,
                    $exception,
                );
            }

            throw $exception;
        }
    }

    private function canInstantiateWithoutArguments(string $class): bool
    {
        $reflection = new ReflectionClass($class);

        $constructor = $reflection->getConstructor();

        if (!$reflection->isInstantiable()) {
            return false;
        }

        if ($constructor === null) {
            return true;
        }

        return $constructor->getNumberOfRequiredParameters() === 0;
    }

    private function wrapCallable(callable $callable): callable
    {
        return function (ServerRequestInterface $request) use ($callable) {
            if (method_exists($this->container, 'call')) {
                $params      = $request->getAttributes();
                $routeParams = $params['_route_params'] ?? [];
                unset($params['_route'], $params['_route_params']);

                $params['psrRequest'] = $request;

                $appRequest       = null;
                $ref              = $this->reflectCallable($callable);
                $scopeBindings    = (bool) $request->getAttribute('_route_scope_bindings', false);
                $resolvedEntities = [];

                foreach ($ref->getParameters() as $parameter) {
                    $name     = $parameter->getName();
                    $type     = $parameter->getType();
                    $typeName = $type instanceof ReflectionNamedType ? $type->getName() : null;

                    if ($typeName === ServerRequestInterface::class) {
                        $params[$name] = $request;
                        continue;
                    }

                    if ($typeName === Request::class) {
                        $appRequest ??= $this->resolveAppRequest($request);
                        if ($appRequest !== null) {
                            $params[$name] = $appRequest;
                        }
                    }

                    if (
                        $typeName !== null
                        && class_exists(RequestSchema::class)
                        && class_exists($typeName)
                        && is_subclass_of($typeName, RequestSchema::class)
                    ) {
                        $appRequest ??= $this->resolveAppRequest($request);
                        if ($appRequest === null) {
                            throw new RuntimeException('RequestSchema requires Request and Validator.');
                        }

                        $schema = new $typeName($appRequest);

                        $schema->validate();
                        $params[$name] = $schema;
                    }

                    if ($this->isEntityType($typeName)) {
                        $routeParamExists = is_array($routeParams) && array_key_exists($name, $routeParams);
                        $routeValue       = $routeParamExists ? $routeParams[$name] : null;

                        $entity = $this->resolveEntity($typeName, $name, $parameter, $params, $routeValue, $routeParamExists);
                        if ($entity === null) {
                            throw new InvalidRouteParameterException('Entity not found for parameter: ' . $name);
                        }

                        if ($scopeBindings && $resolvedEntities !== []) {
                            $parent = $resolvedEntities[array_key_last($resolvedEntities)];
                            if (!$this->isScoped($parent, $entity, $request)) {
                                throw new InvalidRouteParameterException('Scoped entity mismatch for parameter: ' . $name);
                            }
                        }

                        $resolvedEntities[] = $entity;
                        $params[$name]      = $entity;
                    }
                }

                if (!isset($params['request'])) {
                    $params['request'] = $request;
                }

                return $this->container->call($callable, $params);
            }

            return $callable($request);
        };
    }

    private function reflectCallable(callable $callable): ReflectionFunctionAbstract
    {
        if (is_array($callable)) {
            return new ReflectionMethod($callable[0], $callable[1]);
        }

        if (is_object($callable) && !($callable instanceof Closure)) {
            return new ReflectionMethod($callable, '__invoke');
        }

        return new ReflectionFunction($callable);
    }

    private function resolveAppRequest(ServerRequestInterface $request): ?object
    {
        if (!class_exists(Request::class) || !interface_exists(ValidatorInterface::class)) {
            return null;
        }

        if (!$this->container->has(ValidatorInterface::class)) {
            return null;
        }

        $validator = $this->container->get(ValidatorInterface::class);

        return new Request($request, $validator);
    }

    private function isEntityType(?string $typeName): bool
    {
        if ($typeName === null || $typeName === '') {
            return false;
        }

        if (!interface_exists('PhpSoftBox\\Orm\\Contracts\\EntityInterface')) {
            return false;
        }

        return class_exists($typeName) && is_subclass_of($typeName, 'PhpSoftBox\\Orm\\Contracts\\EntityInterface');
    }

    private function resolveEntity(
        string $typeName,
        string $paramName,
        ReflectionParameter $parameter,
        array $params,
        mixed $routeValue = null,
        bool $routeParamExists = false,
    ): ?object {
        if (
            isset($params[$paramName])
            && is_object($params[$paramName])
            && is_a($params[$paramName], $typeName)
        ) {
            return $params[$paramName];
        }

        if ($routeParamExists) {
            $value = $routeValue;
        } elseif (array_key_exists($paramName, $params)) {
            $value = $params[$paramName];
        } else {
            $value = null;
        }

        if ($value === null || $value === '') {
            return null;
        }

        if (is_string($value) && ctype_digit($value)) {
            $value = (int) $value;
        }

        $entityManager = $this->resolveEntityManager($typeName);
        if ($entityManager === null) {
            throw new RuntimeException('Entity binding requires EntityManagerInterface in container.');
        }

        $metadata = $this->loadMetadata($parameter);

        if ($metadata['withDeleted']) {
            $repo = $entityManager->repository($typeName);
            if (is_object($repo) && method_exists($repo, 'findWithDeleted')) {
                $entity = $repo->findWithDeleted($value);
                if ($entity !== null) {
                    $this->loadEntityRelations($entityManager, $entity, $metadata['with']);
                }

                return $entity;
            }
        }

        $entity = $entityManager->find($typeName, $value);
        if ($entity !== null) {
            $this->loadEntityRelations($entityManager, $entity, $metadata['with']);
        }

        return $entity;
    }

    /**
     * @return array{withDeleted: bool, with: list<string>}
     */
    private function loadMetadata(ReflectionParameter $parameter): array
    {
        if (class_exists(ResolveEntity::class)) {
            foreach ($parameter->getAttributes(ResolveEntity::class) as $attribute) {
                /** @var ResolveEntity $instance */
                $instance = $attribute->newInstance();

                return [
                    'withDeleted' => $instance->withDeleted,
                    'with'        => $instance->with,
                ];
            }
        }

        if (class_exists(WithDeleted::class)) {
            foreach ($parameter->getAttributes(WithDeleted::class) as $attribute) {
                /** @var WithDeleted $instance */
                $instance = $attribute->newInstance();

                return [
                    'withDeleted' => $instance->value,
                    'with'        => [],
                ];
            }
        }

        return [
            'withDeleted' => false,
            'with'        => [],
        ];
    }

    /**
     * @param list<string> $relations
     */
    private function loadEntityRelations(object $entityManager, object $entity, array $relations): void
    {
        if ($relations === [] || !method_exists($entityManager, 'load')) {
            return;
        }

        $normalized = array_values($relations);
        if ($normalized === []) {
            return;
        }

        $entityManager->load($entity, $normalized);
    }

    private function resolveEntityManager(?string $entityClass = null): ?object
    {
        if ($entityClass !== null) {
            $entityManager = $this->resolveEntityManagerFromEntityAwareRegistry($entityClass);
            if ($entityManager !== null) {
                return $entityManager;
            }
        }

        return $this->resolveDefaultEntityManager();
    }

    private function resolveDefaultEntityManager(): ?object
    {
        if (!interface_exists('PhpSoftBox\\Orm\\Contracts\\EntityManagerInterface')) {
            return null;
        }

        if (!$this->container->has('PhpSoftBox\\Orm\\Contracts\\EntityManagerInterface')) {
            return null;
        }

        return $this->container->get('PhpSoftBox\\Orm\\Contracts\\EntityManagerInterface');
    }

    private function resolveEntityManagerFromEntityAwareRegistry(string $entityClass): ?object
    {
        $registryInterface = 'PhpSoftBox\\Orm\\Contracts\\EntityAwareEntityManagerRegistryInterface';
        if (!interface_exists($registryInterface)) {
            return null;
        }

        if (!$this->container->has($registryInterface)) {
            return null;
        }

        try {
            $registry = $this->container->get($registryInterface);
        } catch (Throwable) {
            return null;
        }

        if (!is_object($registry) || !method_exists($registry, 'forEntity')) {
            return null;
        }

        try {
            return $registry->forEntity($entityClass, true);
        } catch (Throwable) {
            return null;
        }
    }

    private function resolveScopedBindingsResolver(): ?ScopedBindingsResolverInterface
    {
        if (!interface_exists(ScopedBindingsResolverInterface::class)) {
            return null;
        }

        if (!$this->container->has(ScopedBindingsResolverInterface::class)) {
            return null;
        }

        $resolver = $this->container->get(ScopedBindingsResolverInterface::class);
        if (!$resolver instanceof ScopedBindingsResolverInterface) {
            return null;
        }

        return $resolver;
    }

    private function isScoped(object $parent, object $child, ServerRequestInterface $request): bool
    {
        $resolver = $this->resolveScopedBindingsResolver();
        $context  = (array) $request->getAttributes();

        if ($resolver !== null && $resolver->supports($parent, $child, $context)) {
            return $resolver->isScoped($parent, $child, $context);
        }

        return $this->isScopedByOrmMetadata($parent, $child);
    }

    private function isScopedByOrmMetadata(object $parent, object $child): bool
    {
        $entityManager = $this->resolveEntityManager($parent::class);
        if ($entityManager === null) {
            $entityManager = $this->resolveEntityManager($child::class);
        }

        if ($entityManager === null || !method_exists($entityManager, 'metadata') || !method_exists($entityManager, 'connection')) {
            return false;
        }

        $metaProvider = $entityManager->metadata();
        if (!is_object($metaProvider) || !method_exists($metaProvider, 'for')) {
            return false;
        }

        $parentMeta = $metaProvider->for($parent::class);
        $childMeta  = $metaProvider->for($child::class);

        if (!is_object($parentMeta) || !is_object($childMeta)) {
            return false;
        }

        $relation = $this->findRelation($parentMeta, $child::class);
        if ($relation !== null) {
            return $this->relationExists($entityManager, $parentMeta, $childMeta, $parent, $child, $relation);
        }

        $relation = $this->findRelation($childMeta, $parent::class);
        if ($relation !== null) {
            return $this->relationExists($entityManager, $childMeta, $parentMeta, $child, $parent, $relation);
        }

        return false;
    }

    private function findRelation(object $meta, string $targetClass): ?object
    {
        if (!property_exists($meta, 'relations') || !is_array($meta->relations)) {
            return null;
        }

        foreach ($meta->relations as $relation) {
            if (!is_object($relation)) {
                continue;
            }

            if (property_exists($relation, 'targetEntity') && $relation->targetEntity === $targetClass) {
                return $relation;
            }

            if (
                property_exists($relation, 'type')
                && $relation->type === 'morph_to'
                && property_exists($relation, 'morphMap')
                && is_array($relation->morphMap)
            ) {
                foreach ($relation->morphMap as $mappedClass) {
                    if ($mappedClass === $targetClass) {
                        return $relation;
                    }
                }
            }
        }

        return null;
    }

    private function relationExists(
        object $entityManager,
        object $ownerMeta,
        object $relatedMeta,
        object $owner,
        object $related,
        object $relation,
    ): bool {
        if (!property_exists($relation, 'type')) {
            return false;
        }

        $ownerId   = $this->normalizeId($owner->id());
        $relatedId = $this->normalizeId($related->id());

        if ($ownerId === null || $relatedId === null) {
            return false;
        }

        if ($relation->type === 'many_to_one') {
            $ownerValue   = $this->getEntityValueByColumn($ownerMeta, $owner, (string) $relation->joinColumn);
            $relatedValue = $this->getEntityValueByColumn($relatedMeta, $related, (string) $relation->referencedColumn);

            return $this->normalizeId($ownerValue) === $this->normalizeId($relatedValue);
        }

        if (in_array($relation->type, ['has_one', 'has_many'], true)) {
            $ownerValue   = $this->getEntityValueByColumn($ownerMeta, $owner, (string) $relation->localKey);
            $relatedValue = $this->getEntityValueByColumn($relatedMeta, $related, (string) $relation->foreignKey);

            return $this->normalizeId($ownerValue) === $this->normalizeId($relatedValue);
        }

        if ($relation->type === 'belongs_to_many') {
            if (!method_exists($entityManager, 'connection')) {
                return false;
            }

            $connection = $entityManager->connection();
            if (!is_object($connection) || !method_exists($connection, 'query')) {
                return false;
            }

            $query = $connection
                ->query()
                ->select('1')
                ->from((string) $relation->pivotTable)
                ->where((string) $relation->foreignPivotKey . ' = :owner_id', ['owner_id' => $ownerId])
                ->where((string) $relation->relatedPivotKey . ' = :related_id', ['related_id' => $relatedId])
                ->limit(1);

            return $query->fetchOne() !== null;
        }

        if ($relation->type === 'has_many_through') {
            if (
                !property_exists($relation, 'throughEntity')
                || !property_exists($relation, 'firstKey')
                || !property_exists($relation, 'secondKey')
                || !property_exists($relation, 'localKey')
                || !property_exists($relation, 'targetKey')
            ) {
                return false;
            }

            $ownerValue   = $this->getEntityValueByColumn($ownerMeta, $owner, (string) $relation->localKey);
            $relatedValue = $this->getEntityValueByColumn($relatedMeta, $related, (string) $relation->targetKey);

            $ownerIdValue   = $this->normalizeId($ownerValue);
            $relatedIdValue = $this->normalizeId($relatedValue);

            if ($ownerIdValue === null || $relatedIdValue === null) {
                return false;
            }

            if (!method_exists($entityManager, 'metadata') || !method_exists($entityManager, 'connection')) {
                return false;
            }

            $metaProvider = $entityManager->metadata();
            if (!is_object($metaProvider) || !method_exists($metaProvider, 'for')) {
                return false;
            }

            $throughMeta = $metaProvider->for((string) $relation->throughEntity);
            if (!is_object($throughMeta) || !property_exists($throughMeta, 'table')) {
                return false;
            }

            $connection = $entityManager->connection();
            if (!is_object($connection) || !method_exists($connection, 'query')) {
                return false;
            }

            $query = $connection
                ->query()
                ->select('1')
                ->from((string) $throughMeta->table)
                ->where((string) $relation->firstKey . ' = :__psb_owner_id', ['__psb_owner_id' => $ownerIdValue])
                ->where((string) $relation->secondKey . ' = :__psb_related_id', ['__psb_related_id' => $relatedIdValue])
                ->limit(1);

            return $query->fetchOne() !== null;
        }

        if ($relation->type === 'morph_many') {
            if (
                !property_exists($relation, 'morphTypeColumn')
                || !property_exists($relation, 'morphIdColumn')
                || !property_exists($relation, 'morphTypeValue')
                || !property_exists($relation, 'localKey')
            ) {
                return false;
            }

            $ownerValue     = $this->getEntityValueByColumn($ownerMeta, $owner, (string) $relation->localKey);
            $relatedMorphId = $this->getEntityValueByColumn($relatedMeta, $related, (string) $relation->morphIdColumn);
            $relatedType    = $this->getEntityValueByColumn($relatedMeta, $related, (string) $relation->morphTypeColumn);

            $ownerIdValue   = $this->normalizeId($ownerValue);
            $relatedIdValue = $this->normalizeId($relatedMorphId);

            if ($ownerIdValue === null || $relatedIdValue === null) {
                return false;
            }

            return $ownerIdValue === $relatedIdValue && $relatedType === $relation->morphTypeValue;
        }

        if ($relation->type === 'morph_to') {
            if (
                !property_exists($relation, 'morphTypeColumn')
                || !property_exists($relation, 'morphIdColumn')
                || !property_exists($relation, 'morphMap')
            ) {
                return false;
            }

            $ownerType = $this->getEntityValueByColumn($ownerMeta, $owner, (string) $relation->morphTypeColumn);
            $ownerId   = $this->getEntityValueByColumn($ownerMeta, $owner, (string) $relation->morphIdColumn);

            $ownerIdValue = $this->normalizeId($ownerId);
            if ($ownerIdValue === null || !is_array($relation->morphMap)) {
                return false;
            }

            $expectedType = null;
            foreach ($relation->morphMap as $typeValue => $targetClass) {
                if ($targetClass === $related::class) {
                    $expectedType = $typeValue;
                    break;
                }
            }

            if ($expectedType === null) {
                return false;
            }

            $relatedId = $this->normalizeId($related->id());
            if ($relatedId === null) {
                return false;
            }

            return (string) $ownerType === (string) $expectedType && $ownerIdValue === $relatedId;
        }

        return false;
    }

    private function getEntityValueByColumn(object $meta, object $entity, string $column): mixed
    {
        if (property_exists($meta, 'columns') && is_array($meta->columns)) {
            foreach ($meta->columns as $property => $columnMeta) {
                if (!is_object($columnMeta) || !property_exists($columnMeta, 'column')) {
                    continue;
                }

                if ($columnMeta->column === $column) {
                    return $entity->{$property} ?? null;
                }
            }
        }

        if (property_exists($entity, $column)) {
            return $entity->{$column};
        }

        return null;
    }

    private function normalizeId(mixed $value): int|string|null
    {
        if ($value === null) {
            return null;
        }

        if (interface_exists('Ramsey\\Uuid\\UuidInterface') && $value instanceof UuidInterface) {
            return $value->toString();
        }

        if (is_int($value) || is_string($value)) {
            return $value;
        }

        return null;
    }
}
