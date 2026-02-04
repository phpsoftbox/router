<?php

declare(strict_types=1);

namespace PhpSoftBox\Router\Tests\Fixtures;

use PhpSoftBox\Http\Message\Response;

final class OrmScopeController
{
    public function scopedThrough(DummyThroughParent $parent, DummyThroughChild $child): Response
    {
        return new Response(200, [
            'X-Parent' => (string) $parent->id(),
            'X-Child'  => (string) $child->id(),
        ]);
    }

    public function scopedMorphMany(DummyMorphParent $parent, DummyMorphChild $child): Response
    {
        return new Response(200, [
            'X-Parent' => (string) $parent->id(),
            'X-Child'  => (string) $child->id(),
        ]);
    }

    public function scopedMorphTo(DummyMorphParent $parent, DummyMorphChild $child): Response
    {
        return new Response(200, [
            'X-Parent' => (string) $parent->id(),
            'X-Child'  => (string) $child->id(),
        ]);
    }
}
