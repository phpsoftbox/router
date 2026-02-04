<?php

declare(strict_types=1);

namespace PhpSoftBox\Router\Binding;

interface ScopedBindingsResolverInterface
{
    /**
     * Определяет, нужно ли применять scoped-проверку к паре сущностей.
     *
     * @param array<string, mixed> $context
     */
    public function supports(object $parent, object $child, array $context = []): bool;

    /**
     * Проверяет, что сущности действительно связаны.
     *
     * @param array<string, mixed> $context
     */
    public function isScoped(object $parent, object $child, array $context = []): bool;
}
