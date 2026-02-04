<?php

declare(strict_types=1);

namespace PhpSoftBox\Router\Tests;

require_once __DIR__ . '/Fixtures/OrmContracts/EntityInterface.php';
require_once __DIR__ . '/Fixtures/OrmContracts/EntityManagerInterface.php';
require_once __DIR__ . '/Fixtures/OrmContracts/EntityManagerRegistryInterface.php';
require_once __DIR__ . '/Fixtures/OrmContracts/EntityAwareEntityManagerRegistryInterface.php';

use PhpSoftBox\Http\Message\ServerRequest;
use PhpSoftBox\Orm\Contracts\EntityManagerInterface;
use PhpSoftBox\Router\Dispatcher;
use PhpSoftBox\Router\Handler\ContainerHandlerResolver;
use PhpSoftBox\Router\Route;
use PhpSoftBox\Router\Tests\Fixtures\DummyColumnMeta;
use PhpSoftBox\Router\Tests\Fixtures\DummyConnection;
use PhpSoftBox\Router\Tests\Fixtures\DummyEntityManagerRegistry;
use PhpSoftBox\Router\Tests\Fixtures\DummyEntityManagerWithMetadata;
use PhpSoftBox\Router\Tests\Fixtures\DummyMetadata;
use PhpSoftBox\Router\Tests\Fixtures\DummyMetadataProvider;
use PhpSoftBox\Router\Tests\Fixtures\DummyMorphChild;
use PhpSoftBox\Router\Tests\Fixtures\DummyMorphParent;
use PhpSoftBox\Router\Tests\Fixtures\DummyMorphVideo;
use PhpSoftBox\Router\Tests\Fixtures\DummyThroughChild;
use PhpSoftBox\Router\Tests\Fixtures\DummyThroughParent;
use PhpSoftBox\Router\Tests\Fixtures\DummyThroughPivot;
use PhpSoftBox\Router\Tests\Fixtures\OrmScopeController;
use PhpSoftBox\Router\Tests\Utils\ContainerCallStub;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\CoversMethod;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(ContainerHandlerResolver::class)]
#[CoversMethod(ContainerHandlerResolver::class, 'resolve')]
final class ContainerHandlerResolverOrmScopeTest extends TestCase
{
    /**
     * Проверяем scoped bindings для HasManyThrough.
     */
    #[Test]
    public function testScopedBindingsSupportsHasManyThrough(): void
    {
        $parent = new DummyThroughParent(1);
        $child  = new DummyThroughChild(10);

        $relation = (object) [
            'type'          => 'has_many_through',
            'targetEntity'  => DummyThroughChild::class,
            'throughEntity' => DummyThroughPivot::class,
            'firstKey'      => 'parent_id',
            'secondKey'     => 'child_id',
            'localKey'      => 'id',
            'targetKey'     => 'id',
        ];

        $meta = new DummyMetadataProvider([
            DummyThroughParent::class => new DummyMetadata('parents', [
                'id' => new DummyColumnMeta('id'),
            ], [$relation]),
            DummyThroughChild::class => new DummyMetadata('children', [
                'id' => new DummyColumnMeta('id'),
            ]),
            DummyThroughPivot::class => new DummyMetadata('parent_children'),
        ]);

        $connection = new DummyConnection([
            'parent_children' => [
                '1|10' => true,
            ],
        ]);

        $entityManager = new DummyEntityManagerWithMetadata([
            DummyThroughParent::class => [1 => $parent],
            DummyThroughChild::class  => [10 => $child],
        ], $meta, $connection);

        $container = new ContainerCallStub([
            EntityManagerInterface::class => $entityManager,
            OrmScopeController::class     => new OrmScopeController(),
        ]);

        $resolver = new ContainerHandlerResolver($container);

        $dispatcher = new Dispatcher($resolver);

        $route = new Route('GET', '/parents/{parent}/children/{child}', [OrmScopeController::class, 'scopedThrough'], scopeBindings: true);

        $request = new ServerRequest('GET', 'https://example.com/parents/1/children/10')
            ->withAttribute('parent', 1)
            ->withAttribute('child', 10)
            ->withAttribute('_route_scope_bindings', true);

        $response = $dispatcher->dispatch($route, $request);

        $this->assertSame(200, $response->getStatusCode());
    }

    /**
     * Проверяем, что scoped bindings для HasManyThrough используют entity-aware EntityManager (forEntity),
     * а не default EntityManager.
     */
    #[Test]
    public function testScopedBindingsUsesEntityAwareEntityManagerForOrmScope(): void
    {
        $parent = new DummyThroughParent(1);
        $child  = new DummyThroughChild(10);

        $relation = (object) [
            'type'          => 'has_many_through',
            'targetEntity'  => DummyThroughChild::class,
            'throughEntity' => DummyThroughPivot::class,
            'firstKey'      => 'parent_id',
            'secondKey'     => 'child_id',
            'localKey'      => 'id',
            'targetKey'     => 'id',
        ];

        $meta = new DummyMetadataProvider([
            DummyThroughParent::class => new DummyMetadata('parents', [
                'id' => new DummyColumnMeta('id'),
            ], [$relation]),
            DummyThroughChild::class => new DummyMetadata('children', [
                'id' => new DummyColumnMeta('id'),
            ]),
            DummyThroughPivot::class => new DummyMetadata('parent_children'),
        ]);

        $defaultEntityManager = new DummyEntityManagerWithMetadata(
            [
                DummyThroughParent::class => [],
                DummyThroughChild::class  => [],
            ],
            $meta,
            new DummyConnection(),
        );

        $tenantEntityManager = new DummyEntityManagerWithMetadata(
            [
                DummyThroughParent::class => [1 => $parent],
                DummyThroughChild::class  => [10 => $child],
            ],
            $meta,
            new DummyConnection([
                'parent_children' => [
                    '1|10' => true,
                ],
            ]),
        );

        $registry = new DummyEntityManagerRegistry(
            defaultEntityManager: $defaultEntityManager,
            entityManagersByConnection: [
                DummyThroughParent::class => $tenantEntityManager,
                DummyThroughChild::class  => $tenantEntityManager,
            ],
        );

        $container = new ContainerCallStub([
            'PhpSoftBox\\Orm\\Contracts\\EntityAwareEntityManagerRegistryInterface' => $registry,
            EntityManagerInterface::class                                           => $defaultEntityManager,
            OrmScopeController::class                                               => new OrmScopeController(),
        ]);

        $resolver = new ContainerHandlerResolver($container);

        $dispatcher = new Dispatcher($resolver);

        $route = new Route('GET', '/parents/{parent}/children/{child}', [OrmScopeController::class, 'scopedThrough'], scopeBindings: true);

        $request = new ServerRequest('GET', 'https://example.com/parents/1/children/10')
            ->withAttribute('parent', 1)
            ->withAttribute('child', 10)
            ->withAttribute('_route_scope_bindings', true);

        $response = $dispatcher->dispatch($route, $request);

        $this->assertSame(200, $response->getStatusCode());
    }

    /**
     * Проверяем scoped bindings для MorphMany.
     */
    #[Test]
    public function testScopedBindingsSupportsMorphMany(): void
    {
        $parent = new DummyMorphParent(5);
        $child  = new DummyMorphChild(7, 'post', 5);

        $relation = (object) [
            'type'            => 'morph_many',
            'targetEntity'    => DummyMorphChild::class,
            'localKey'        => 'id',
            'morphTypeColumn' => 'commentable_type',
            'morphIdColumn'   => 'commentable_id',
            'morphTypeValue'  => 'post',
        ];

        $meta = new DummyMetadataProvider([
            DummyMorphParent::class => new DummyMetadata('posts', [
                'id' => new DummyColumnMeta('id'),
            ], [$relation]),
            DummyMorphChild::class => new DummyMetadata('comments', [
                'id'               => new DummyColumnMeta('id'),
                'commentable_type' => new DummyColumnMeta('commentable_type'),
                'commentable_id'   => new DummyColumnMeta('commentable_id'),
            ]),
        ]);

        $entityManager = new DummyEntityManagerWithMetadata([
            DummyMorphParent::class => [5 => $parent],
            DummyMorphChild::class  => [7 => $child],
        ], $meta, new DummyConnection());

        $container = new ContainerCallStub([
            EntityManagerInterface::class => $entityManager,
            OrmScopeController::class     => new OrmScopeController(),
        ]);

        $resolver = new ContainerHandlerResolver($container);

        $dispatcher = new Dispatcher($resolver);

        $route = new Route('GET', '/parents/{parent}/children/{child}', [OrmScopeController::class, 'scopedMorphMany'], scopeBindings: true);

        $request = new ServerRequest('GET', 'https://example.com/parents/5/children/7')
            ->withAttribute('parent', 5)
            ->withAttribute('child', 7)
            ->withAttribute('_route_scope_bindings', true);

        $response = $dispatcher->dispatch($route, $request);

        $this->assertSame(200, $response->getStatusCode());
    }

    /**
     * Проверяем scoped bindings для MorphTo.
     */
    #[Test]
    public function testScopedBindingsSupportsMorphTo(): void
    {
        $parent = new DummyMorphParent(99);
        $child  = new DummyMorphChild(15, 'post', 99);

        $relation = (object) [
            'type'            => 'morph_to',
            'morphTypeColumn' => 'commentable_type',
            'morphIdColumn'   => 'commentable_id',
            'morphMap'        => [
                'post'  => DummyMorphParent::class,
                'video' => DummyMorphVideo::class,
            ],
        ];

        $meta = new DummyMetadataProvider([
            DummyMorphParent::class => new DummyMetadata('posts', [
                'id' => new DummyColumnMeta('id'),
            ]),
            DummyMorphChild::class => new DummyMetadata('comments', [
                'id'               => new DummyColumnMeta('id'),
                'commentable_type' => new DummyColumnMeta('commentable_type'),
                'commentable_id'   => new DummyColumnMeta('commentable_id'),
            ], [$relation]),
        ]);

        $entityManager = new DummyEntityManagerWithMetadata([
            DummyMorphParent::class => [99 => $parent],
            DummyMorphChild::class  => [15 => $child],
        ], $meta, new DummyConnection());

        $container = new ContainerCallStub([
            EntityManagerInterface::class => $entityManager,
            OrmScopeController::class     => new OrmScopeController(),
        ]);

        $resolver = new ContainerHandlerResolver($container);

        $dispatcher = new Dispatcher($resolver);

        $route = new Route('GET', '/parents/{parent}/children/{child}', [OrmScopeController::class, 'scopedMorphTo'], scopeBindings: true);

        $request = new ServerRequest('GET', 'https://example.com/parents/99/children/15')
            ->withAttribute('parent', 99)
            ->withAttribute('child', 15)
            ->withAttribute('_route_scope_bindings', true);

        $response = $dispatcher->dispatch($route, $request);

        $this->assertSame(200, $response->getStatusCode());
    }
}
