<?php

declare(strict_types=1);

namespace PhpSoftBox\Router\Tests\Fixtures;

use PhpSoftBox\Http\Message\Response;
use PhpSoftBox\Router\Attributes\ResolveEntity;
use PhpSoftBox\Router\Attributes\WithDeleted;
use Psr\Http\Message\ServerRequestInterface;

use function is_array;

final class EntityBindingController
{
    public function show(DummyEntity $entity): Response
    {
        return new Response(200, ['X-Entity' => (string) $entity->id()]);
    }

    public function showRequestParams(DummyEntity $entity, ServerRequestInterface $request): Response
    {
        $attribute   = $request->getAttribute('entity');
        $routeParams = $request->getAttribute('_route_params');

        return new Response(200, [
            'X-Entity'           => (string) $entity->id(),
            'X-Attribute-Entity' => $attribute === $entity ? '1' : '0',
            'X-Route-Entity'     => is_array($routeParams) && ($routeParams['entity'] ?? null) === $entity ? '1' : '0',
        ]);
    }

    public function schemaBeforeEntity(EntityRouteRequestSchema $schema, DummyEntity $entity): Response
    {
        return new Response(200, [
            'X-Same-Entity' => $schema->routeEntity() === $entity ? '1' : '0',
        ]);
    }

    public function entityBeforeSchema(DummyEntity $entity, EntityRouteRequestSchema $schema): Response
    {
        return new Response(200, [
            'X-Same-Entity' => $schema->routeEntity() === $entity ? '1' : '0',
        ]);
    }

    public function showDeleted(#[WithDeleted] DummyEntity $entity): Response
    {
        return new Response(200, ['X-Entity' => (string) $entity->id()]);
    }

    public function showDeletedWithRelations(
        #[ResolveEntity(withDeleted: true, with: ['roles', 'profile'])]
        DummyEntity $entity,
    ): Response {
        return new Response(200, ['X-Entity' => (string) $entity->id()]);
    }

    public function showBySlug(
        #[ResolveEntity(withDeleted: true, with: 'profile', column: 'slug')]
        DummyEntity $entity,
    ): Response {
        return new Response(200, ['X-Entity' => (string) $entity->id()]);
    }

    public function showTenantEntity(TenantBoundEntity $entity): Response
    {
        return new Response(200, ['X-Entity' => (string) $entity->id()]);
    }

    public function scoped(DummyParent $parent, DummyChild $child): Response
    {
        return new Response(200, [
            'X-Parent' => (string) $parent->id(),
            'X-Child'  => (string) $child->id(),
        ]);
    }
}
