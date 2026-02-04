<?php

declare(strict_types=1);

namespace PhpSoftBox\Router\Tests;

require_once __DIR__ . '/Fixtures/OrmContracts/EntityInterface.php';
require_once __DIR__ . '/Fixtures/OrmMetadata/MetadataProviderInterface.php';
require_once __DIR__ . '/Fixtures/OrmContracts/EntityManagerInterface.php';
require_once __DIR__ . '/Fixtures/OrmContracts/EntityManagerRegistryInterface.php';
require_once __DIR__ . '/Fixtures/OrmContracts/EntityAwareEntityManagerRegistryInterface.php';

use PhpSoftBox\Http\Message\ServerRequest;
use PhpSoftBox\Orm\Contracts\EntityManagerInterface;
use PhpSoftBox\Router\Dispatcher;
use PhpSoftBox\Router\Exception\InvalidRouteParameterException;
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
use PhpSoftBox\Router\Tests\Fixtures\DummyScopedCompany;
use PhpSoftBox\Router\Tests\Fixtures\DummyScopedProduct;
use PhpSoftBox\Router\Tests\Fixtures\DummyScopedUser;
use PhpSoftBox\Router\Tests\Fixtures\DummyThroughChild;
use PhpSoftBox\Router\Tests\Fixtures\DummyThroughParent;
use PhpSoftBox\Router\Tests\Fixtures\DummyThroughPivot;
use PhpSoftBox\Router\Tests\Fixtures\OrmScopeController;
use PhpSoftBox\Router\Tests\Utils\ContainerCallStub;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\CoversMethod;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;

use function method_exists;

#[CoversClass(ContainerHandlerResolver::class)]
#[CoversMethod(ContainerHandlerResolver::class, 'resolve')]
final class ContainerHandlerResolverOrmScopeTest extends TestCase
{
    /**
     * Проверяем, что route parameter имеет приоритет над одноимённой авторизованной entity в request attributes.
     *
     * @see ContainerHandlerResolver::resolve()
     * @see Dispatcher::dispatch()
     */
    #[Test]
    public function testRouteParamOverridesEntityRequestAttribute(): void
    {
        $authenticatedUser = new DummyScopedUser(1);
        $routeUser         = new DummyScopedUser(7);

        $entityManager = new DummyEntityManagerWithMetadata(
            [DummyScopedUser::class => [7 => $routeUser]],
            $this->scopedMetadata(),
            new DummyConnection(),
        );

        $container = new ContainerCallStub([
            EntityManagerInterface::class => $entityManager,
            OrmScopeController::class     => new OrmScopeController(),
        ]);

        $dispatcher = new Dispatcher(new ContainerHandlerResolver($container));
        $route      = new Route('GET', '/users/{user}', [OrmScopeController::class, 'showUser']);
        $request    = new ServerRequest('GET', 'https://example.com/users/7')
            ->withAttribute('user', $authenticatedUser)
            ->withAttribute('_route_params', ['user' => 7]);

        $response = $dispatcher->dispatch($route, $request);

        $this->assertSame('7', $response->getHeaderLine('X-User'));
    }

    /**
     * Проверяем has_many через публичный metadataProvider() без внутреннего metadata().
     *
     * @see ContainerHandlerResolver::resolve()
     * @see Dispatcher::dispatch()
     */
    #[Test]
    public function testScopedBindingsAcceptsRelatedHasManyUsingMetadataProvider(): void
    {
        $user    = new DummyScopedUser(7);
        $company = new DummyScopedCompany(5, 7);

        $entityManager = new DummyEntityManagerWithMetadata(
            [
                DummyScopedUser::class    => [7 => $user],
                DummyScopedCompany::class => [5 => $company],
            ],
            $this->scopedMetadata(),
            new DummyConnection(),
        );

        $this->assertFalse(method_exists($entityManager, 'metadata'));

        $response = $this->dispatchHasMany($entityManager);

        $this->assertSame(200, $response->getStatusCode());
    }

    /**
     * Проверяем отклонение несвязанной has_many пары.
     *
     * @see ContainerHandlerResolver::resolve()
     * @see Dispatcher::dispatch()
     */
    #[Test]
    public function testScopedBindingsRejectsUnrelatedHasMany(): void
    {
        $user    = new DummyScopedUser(7);
        $company = new DummyScopedCompany(5, 99);

        $entityManager = new DummyEntityManagerWithMetadata(
            [
                DummyScopedUser::class    => [7 => $user],
                DummyScopedCompany::class => [5 => $company],
            ],
            $this->scopedMetadata(),
            new DummyConnection(),
        );

        $this->expectException(InvalidRouteParameterException::class);

        $this->dispatchHasMany($entityManager);
    }

    /**
     * Проверяем две последовательные scoped-проверки в цепочке User -> Company -> Product.
     *
     * @see ContainerHandlerResolver::resolve()
     * @see Dispatcher::dispatch()
     */
    #[Test]
    public function testScopedBindingsAcceptsThreeEntityChain(): void
    {
        $user    = new DummyScopedUser(7);
        $company = new DummyScopedCompany(5, 7);
        $product = new DummyScopedProduct(4448, 5);

        $entityManager = new DummyEntityManagerWithMetadata(
            [
                DummyScopedUser::class    => [7 => $user],
                DummyScopedCompany::class => [5 => $company],
                DummyScopedProduct::class => [4448 => $product],
            ],
            $this->scopedMetadata(),
            new DummyConnection(),
        );

        $container = new ContainerCallStub([
            EntityManagerInterface::class => $entityManager,
            OrmScopeController::class     => new OrmScopeController(),
        ]);

        $dispatcher = new Dispatcher(new ContainerHandlerResolver($container));
        $route      = new Route(
            'GET',
            '/users/{user}/companies/{company}/products/{product}',
            [OrmScopeController::class, 'scopedChain'],
            scopeBindings: true,
        );
        $request = new ServerRequest('GET', 'https://example.com/users/7/companies/5/products/4448')
            ->withAttribute('user', 7)
            ->withAttribute('company', 5)
            ->withAttribute('product', 4448)
            ->withAttribute('_route_scope_bindings', true);

        $response = $dispatcher->dispatch($route, $request);

        $this->assertSame(200, $response->getStatusCode());
    }

    /**
     * Проверяем контролируемый scoped mismatch для manager без metadataProvider().
     *
     * @see ContainerHandlerResolver::resolve()
     * @see Dispatcher::dispatch()
     */
    #[Test]
    public function testScopedBindingsRejectsManagerWithoutMetadataProvider(): void
    {
        $user    = new DummyScopedUser(7);
        $company = new DummyScopedCompany(5, 7);

        $entityManager = new class ($user, $company) {
            public function __construct(
                private readonly DummyScopedUser $user,
                private readonly DummyScopedCompany $company,
            ) {
            }

            public function find(string $entityClass, int|string $id): ?object
            {
                return match ($entityClass) {
                    DummyScopedUser::class    => $id === 7 ? $this->user : null,
                    DummyScopedCompany::class => $id === 5 ? $this->company : null,
                    default                   => null,
                };
            }
        };

        $this->expectException(InvalidRouteParameterException::class);

        $this->dispatchHasMany($entityManager);
    }

    /**
     * Проверяем scoped bindings для HasManyThrough.
     *
     * @see ContainerHandlerResolver::resolve()
     * @see Dispatcher::dispatch()
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
     *
     * @see ContainerHandlerResolver::resolve()
     * @see Dispatcher::dispatch()
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
     *
     * @see ContainerHandlerResolver::resolve()
     * @see Dispatcher::dispatch()
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
     *
     * @see ContainerHandlerResolver::resolve()
     * @see Dispatcher::dispatch()
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

    private function scopedMetadata(): DummyMetadataProvider
    {
        $companies = (object) [
            'type'         => 'has_many',
            'targetEntity' => DummyScopedCompany::class,
            'localKey'     => 'id',
            'foreignKey'   => 'user_id',
        ];
        $products = (object) [
            'type'         => 'has_many',
            'targetEntity' => DummyScopedProduct::class,
            'localKey'     => 'id',
            'foreignKey'   => 'company_id',
        ];

        return new DummyMetadataProvider([
            DummyScopedUser::class => new DummyMetadata('users', [
                'id' => new DummyColumnMeta('id'),
            ], [$companies]),
            DummyScopedCompany::class => new DummyMetadata('companies', [
                'id'     => new DummyColumnMeta('id'),
                'userId' => new DummyColumnMeta('user_id'),
            ], [$products]),
            DummyScopedProduct::class => new DummyMetadata('products', [
                'id'        => new DummyColumnMeta('id'),
                'companyId' => new DummyColumnMeta('company_id'),
            ]),
        ]);
    }

    private function dispatchHasMany(object $entityManager): ResponseInterface
    {
        $container = new ContainerCallStub([
            EntityManagerInterface::class => $entityManager,
            OrmScopeController::class     => new OrmScopeController(),
        ]);

        $dispatcher = new Dispatcher(new ContainerHandlerResolver($container));
        $route      = new Route(
            'GET',
            '/users/{user}/companies/{company}',
            [OrmScopeController::class, 'scopedHasMany'],
            scopeBindings: true,
        );
        $request = new ServerRequest('GET', 'https://example.com/users/7/companies/5')
            ->withAttribute('user', 7)
            ->withAttribute('company', 5)
            ->withAttribute('_route_scope_bindings', true);

        return $dispatcher->dispatch($route, $request);
    }
}
