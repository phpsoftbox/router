<?php

declare(strict_types=1);

namespace PhpSoftBox\Router\Tests\Fixtures;

use PhpSoftBox\Http\Message\Response;

final class OrmScopeController
{
    public function showUser(DummyScopedUser $user): Response
    {
        return new Response(200, ['X-User' => (string) $user->id()]);
    }

    public function scopedHasMany(DummyScopedUser $user, DummyScopedCompany $company): Response
    {
        return new Response(200, [
            'X-User'    => (string) $user->id(),
            'X-Company' => (string) $company->id(),
        ]);
    }

    public function scopedChain(
        DummyScopedUser $user,
        DummyScopedCompany $company,
        DummyScopedProduct $product,
    ): Response {
        return new Response(200, [
            'X-User'    => (string) $user->id(),
            'X-Company' => (string) $company->id(),
            'X-Product' => (string) $product->id(),
        ]);
    }

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
