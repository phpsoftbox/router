<?php

declare(strict_types=1);

namespace PhpSoftBox\Router\Tests\Fixtures;

use PhpSoftBox\Http\Message\Response;
use PhpSoftBox\Router\Attributes\ResolveEntity;
use PhpSoftBox\Router\Attributes\WithDeleted;

final class EntityBindingController
{
    public function show(DummyEntity $entity): Response
    {
        return new Response(200, ['X-Entity' => (string) $entity->id()]);
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
