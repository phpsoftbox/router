<?php

declare(strict_types=1);

namespace PhpSoftBox\Router\Tests\Fixtures;

use PhpSoftBox\Router\Binding\ScopedBindingsResolverInterface;

final class DummyScopedBindingsResolver implements ScopedBindingsResolverInterface
{
    public function __construct(
        private bool $allowed,
    ) {
    }

    public function supports(object $parent, object $child, array $context = []): bool
    {
        return $parent instanceof DummyParent && $child instanceof DummyChild;
    }

    public function isScoped(object $parent, object $child, array $context = []): bool
    {
        return $this->allowed;
    }
}
