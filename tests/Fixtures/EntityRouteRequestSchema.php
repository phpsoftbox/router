<?php

declare(strict_types=1);

namespace PhpSoftBox\Router\Tests\Fixtures;

use PhpSoftBox\Request\RequestSchema;

final class EntityRouteRequestSchema extends RequestSchema
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [];
    }

    public function routeEntity(): DummyEntity
    {
        return $this->route()->entity('entity', DummyEntity::class);
    }
}
