<?php

declare(strict_types=1);

namespace PhpSoftBox\Router\Tests;

require_once __DIR__ . '/Fixtures/OrmContracts/EntityInterface.php';
require_once __DIR__ . '/Fixtures/OrmMetadata/MetadataProviderInterface.php';
require_once __DIR__ . '/Fixtures/OrmContracts/EntityManagerInterface.php';
require_once __DIR__ . '/Fixtures/OrmContracts/EntityManagerRegistryInterface.php';
require_once __DIR__ . '/Fixtures/OrmContracts/EntityAwareEntityManagerRegistryInterface.php';
require_once __DIR__ . '/Fixtures/OrmMetadata/Attributes/Entity.php';

use PhpSoftBox\Http\Message\ServerRequest;
use PhpSoftBox\Orm\Contracts\EntityManagerInterface;
use PhpSoftBox\Router\Binding\ScopedBindingsResolverInterface;
use PhpSoftBox\Router\Dispatcher;
use PhpSoftBox\Router\Exception\InvalidRouteParameterException;
use PhpSoftBox\Router\Handler\ContainerHandlerResolver;
use PhpSoftBox\Router\Route;
use PhpSoftBox\Router\Tests\Fixtures\DummyChild;
use PhpSoftBox\Router\Tests\Fixtures\DummyEntity;
use PhpSoftBox\Router\Tests\Fixtures\DummyEntityManager;
use PhpSoftBox\Router\Tests\Fixtures\DummyEntityManagerRegistry;
use PhpSoftBox\Router\Tests\Fixtures\DummyEntityRepository;
use PhpSoftBox\Router\Tests\Fixtures\DummyParent;
use PhpSoftBox\Router\Tests\Fixtures\DummyScopedBindingsResolver;
use PhpSoftBox\Router\Tests\Fixtures\EntityBindingController;
use PhpSoftBox\Router\Tests\Fixtures\RequestAwareController;
use PhpSoftBox\Router\Tests\Fixtures\RequestSchemaController;
use PhpSoftBox\Router\Tests\Fixtures\RequestSchemaDependency;
use PhpSoftBox\Router\Tests\Fixtures\RequestSchemaWithDependencyController;
use PhpSoftBox\Router\Tests\Fixtures\RouteParamController;
use PhpSoftBox\Router\Tests\Fixtures\TenantBoundEntity;
use PhpSoftBox\Router\Tests\Utils\ContainerCallStub;
use PhpSoftBox\Router\Tests\Utils\ContainerStub;
use PhpSoftBox\Router\Tests\Utils\DummyController;
use PhpSoftBox\Validator\Exception\ValidationException;
use PhpSoftBox\Validator\Validator;
use PhpSoftBox\Validator\ValidatorInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\CoversMethod;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\ContainerInterface;
use RuntimeException;

#[CoversClass(ContainerHandlerResolver::class)]
#[CoversMethod(ContainerHandlerResolver::class, 'resolve')]
#[CoversClass(Dispatcher::class)]
#[CoversMethod(Dispatcher::class, 'dispatch')]
final class ContainerHandlerResolverTest extends TestCase
{
    /**
     * Проверяем, что ContainerHandlerResolver берёт контроллер из контейнера.
     *
     * @see ContainerHandlerResolver::resolve()
     * @see Dispatcher::dispatch()
     */
    #[Test]
    public function testResolvesControllerFromContainer(): void
    {
        $controller = new DummyController();

        $container = new ContainerStub([DummyController::class => $controller]);

        $resolver = new ContainerHandlerResolver($container);

        $dispatcher = new Dispatcher($resolver);

        $route = new Route('GET', '/hi', [DummyController::class, 'hello']);

        $response = $dispatcher->dispatch($route, new ServerRequest('GET', 'https://example.com/hi'));

        $this->assertSame(201, $response->getStatusCode());
    }

    /**
     * Проверяем fallback-инстанциирование контроллера без обязательных зависимостей.
     *
     * @see ContainerHandlerResolver::resolve()
     * @see Dispatcher::dispatch()
     */
    #[Test]
    public function testResolvesControllerWithoutContainerEntry(): void
    {
        $container = new ContainerStub();

        $resolver = new ContainerHandlerResolver($container);

        $dispatcher = new Dispatcher($resolver);
        $route      = new Route('GET', '/hi', [DummyController::class, 'hello']);

        $response = $dispatcher->dispatch($route, new ServerRequest('GET', 'https://example.com/hi'));

        $this->assertSame(201, $response->getStatusCode());
    }

    /**
     * Проверяем, что ошибка контейнера не теряется и оборачивается с контекстом класса.
     *
     * @see ContainerHandlerResolver::resolve()
     * @see Dispatcher::dispatch()
     */
    #[Test]
    public function testResolveInstanceThrowsReadableContainerError(): void
    {
        $container = new class () implements ContainerInterface {
            public function get(string $id): object
            {
                throw new class ('Broken dependency graph') extends RuntimeException implements ContainerExceptionInterface {
                };
            }

            public function has(string $id): bool
            {
                return true;
            }
        };

        $resolver = new ContainerHandlerResolver($container);

        $dispatcher = new Dispatcher($resolver);
        $route      = new Route('GET', '/hi', [DummyController::class, 'hello']);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Failed to resolve handler class from container: ' . DummyController::class);
        $dispatcher->dispatch($route, new ServerRequest('GET', 'https://example.com/hi'));
    }

    /**
     * Проверяем, что при наличии container->call используется инъекция параметров.
     *
     * @see ContainerHandlerResolver::resolve()
     * @see Dispatcher::dispatch()
     */
    #[Test]
    public function testContainerCallInjectsParams(): void
    {
        $container = new ContainerCallStub([RouteParamController::class => new RouteParamController()]);

        $resolver = new ContainerHandlerResolver($container);

        $dispatcher = new Dispatcher($resolver);

        $route = new Route('GET', '/users/{id}', [RouteParamController::class, 'show']);

        $request = new ServerRequest('GET', 'https://example.com/users/42')
            ->withAttribute('id', '42');

        $response = $dispatcher->dispatch($route, $request);

        $this->assertTrue($container->called);
        $this->assertSame('42', $response->getHeaderLine('X-Id'));
    }

    /**
     * Проверяем, что Request внедряется при наличии Validator в контейнере.
     *
     * @see ContainerHandlerResolver::resolve()
     * @see Dispatcher::dispatch()
     */
    #[Test]
    public function testContainerCallInjectsRequest(): void
    {
        $container = new ContainerCallStub([
            ValidatorInterface::class     => new Validator(),
            RequestAwareController::class => new RequestAwareController(),
        ]);

        $resolver = new ContainerHandlerResolver($container);

        $dispatcher = new Dispatcher($resolver);

        $route = new Route('POST', '/request', [RequestAwareController::class, 'show']);

        $request = new ServerRequest('POST', 'https://example.com/request')
            ->withParsedBody(['name' => 'Ivan']);

        $response = $dispatcher->dispatch($route, $request);

        $this->assertTrue($container->called);
        $this->assertSame('Ivan', $response->getHeaderLine('X-Name'));
    }

    /**
     * Проверяем автоподхват RequestSchema и валидацию до запуска контроллера.
     *
     * @see ContainerHandlerResolver::resolve()
     * @see Dispatcher::dispatch()
     */
    #[Test]
    public function testRequestSchemaValidationPasses(): void
    {
        $container = new ContainerCallStub([
            ValidatorInterface::class      => new Validator(),
            RequestSchemaController::class => new RequestSchemaController(),
        ]);

        $resolver = new ContainerHandlerResolver($container);

        $dispatcher = new Dispatcher($resolver);

        $route = new Route('POST', '/schema', [RequestSchemaController::class, 'handle']);

        $request = new ServerRequest('POST', 'https://example.com/schema')
            ->withParsedBody(['email' => 'user@example.com']);

        $response = $dispatcher->dispatch($route, $request);

        $this->assertTrue($container->called);
        $this->assertSame('user@example.com', $response->getHeaderLine('X-Email'));
    }

    /**
     * Проверяем, что RequestSchema создаётся через container->make и получает дополнительные зависимости.
     *
     * @see ContainerHandlerResolver::resolve()
     * @see Dispatcher::dispatch()
     */
    #[Test]
    public function testRequestSchemaCanResolveConstructorDependenciesViaContainerMake(): void
    {
        $container = new ContainerCallStub([
            ValidatorInterface::class                    => new Validator(),
            RequestSchemaDependency::class               => new RequestSchemaDependency(),
            RequestSchemaWithDependencyController::class => new RequestSchemaWithDependencyController(),
        ]);

        $resolver = new ContainerHandlerResolver($container);

        $dispatcher = new Dispatcher($resolver);

        $route = new Route('POST', '/schema-di', [RequestSchemaWithDependencyController::class, 'handle']);

        $request = new ServerRequest('POST', 'https://example.com/schema-di')
            ->withParsedBody(['email' => 'user@example.com']);

        $response = $dispatcher->dispatch($route, $request);

        $this->assertTrue($container->called);
        $this->assertTrue($container->makeCalled);
        $this->assertSame('user@example.com', $response->getHeaderLine('X-Email'));
        $this->assertSame('container', $response->getHeaderLine('X-Schema-Dependency'));
    }

    /**
     * Проверяем, что RequestSchema бросает ValidationException при ошибке валидации.
     *
     * @see ContainerHandlerResolver::resolve()
     * @see Dispatcher::dispatch()
     */
    #[Test]
    public function testRequestSchemaValidationFails(): void
    {
        $container = new ContainerCallStub([
            ValidatorInterface::class      => new Validator(),
            RequestSchemaController::class => new RequestSchemaController(),
        ]);

        $resolver = new ContainerHandlerResolver($container);

        $dispatcher = new Dispatcher($resolver);

        $route = new Route('POST', '/schema', [RequestSchemaController::class, 'handle']);

        $request = new ServerRequest('POST', 'https://example.com/schema');

        $this->expectException(ValidationException::class);
        $dispatcher->dispatch($route, $request);
    }

    /**
     * Проверяем, что сущность резолвится из EntityManager по имени параметра.
     *
     * @see ContainerHandlerResolver::resolve()
     * @see Dispatcher::dispatch()
     */
    #[Test]
    public function testEntityBindingResolvesEntity(): void
    {
        $entity = new DummyEntity(42);

        $entityManager = new DummyEntityManager([
            DummyEntity::class => [42 => $entity],
        ]);

        $container = new ContainerCallStub([
            EntityManagerInterface::class  => $entityManager,
            EntityBindingController::class => new EntityBindingController(),
        ]);

        $resolver = new ContainerHandlerResolver($container);

        $dispatcher = new Dispatcher($resolver);

        $route = new Route('GET', '/entities/{entity}', [EntityBindingController::class, 'show']);

        $request = new ServerRequest('GET', 'https://example.com/entities/42')
            ->withAttribute('entity', 42);

        $response = $dispatcher->dispatch($route, $request);

        $this->assertSame('42', $response->getHeaderLine('X-Entity'));
    }

    /**
     * Проверяем, что entity binding обновляет request attributes и _route_params найденной сущностью.
     *
     * @see ContainerHandlerResolver::resolve()
     * @see Dispatcher::dispatch()
     */
    #[Test]
    public function testEntityBindingUpdatesRequestAttributesAndRouteParams(): void
    {
        $entity = new DummyEntity(42);

        $entityManager = new DummyEntityManager([
            DummyEntity::class => [42 => $entity, 99 => new DummyEntity(99)],
        ]);

        $container = new ContainerCallStub([
            EntityManagerInterface::class  => $entityManager,
            EntityBindingController::class => new EntityBindingController(),
        ]);

        $resolver = new ContainerHandlerResolver($container);

        $dispatcher = new Dispatcher($resolver);

        $route = new Route('GET', '/entities/{entity}', [EntityBindingController::class, 'showRequestParams']);

        $request = new ServerRequest('GET', 'https://example.com/entities/42')
            ->withAttribute('entity', 99)
            ->withAttribute('_route_params', ['entity' => 42]);

        $response = $dispatcher->dispatch($route, $request);

        $this->assertSame('42', $response->getHeaderLine('X-Entity'));
        $this->assertSame('1', $response->getHeaderLine('X-Attribute-Entity'));
        $this->assertSame('1', $response->getHeaderLine('X-Route-Entity'));
    }

    /**
     * Проверяем, что RequestSchema видит уже найденную entity независимо от порядка аргументов.
     *
     * @see ContainerHandlerResolver::resolve()
     * @see Dispatcher::dispatch()
     */
    #[Test]
    public function testRequestSchemaReceivesResolvedEntityRouteParamBeforeEntityArgument(): void
    {
        $entity = new DummyEntity(42);

        $entityManager = new DummyEntityManager([
            DummyEntity::class => [42 => $entity],
        ]);

        $container = new ContainerCallStub([
            EntityManagerInterface::class  => $entityManager,
            ValidatorInterface::class      => new Validator(),
            EntityBindingController::class => new EntityBindingController(),
        ]);

        $resolver = new ContainerHandlerResolver($container);

        $dispatcher = new Dispatcher($resolver);

        $route = new Route('GET', '/entities/{entity}', [EntityBindingController::class, 'schemaBeforeEntity']);

        $request = new ServerRequest('GET', 'https://example.com/entities/42')
            ->withAttribute('entity', 42)
            ->withAttribute('_route_params', ['entity' => 42]);

        $response = $dispatcher->dispatch($route, $request);

        $this->assertSame('1', $response->getHeaderLine('X-Same-Entity'));
    }

    /**
     * Проверяем, что RequestSchema видит уже найденную entity, когда entity-аргумент идёт первым.
     *
     * @see ContainerHandlerResolver::resolve()
     * @see Dispatcher::dispatch()
     */
    #[Test]
    public function testRequestSchemaReceivesResolvedEntityRouteParamAfterEntityArgument(): void
    {
        $entity = new DummyEntity(42);

        $entityManager = new DummyEntityManager([
            DummyEntity::class => [42 => $entity],
        ]);

        $container = new ContainerCallStub([
            EntityManagerInterface::class  => $entityManager,
            ValidatorInterface::class      => new Validator(),
            EntityBindingController::class => new EntityBindingController(),
        ]);

        $resolver = new ContainerHandlerResolver($container);

        $dispatcher = new Dispatcher($resolver);

        $route = new Route('GET', '/entities/{entity}', [EntityBindingController::class, 'entityBeforeSchema']);

        $request = new ServerRequest('GET', 'https://example.com/entities/42')
            ->withAttribute('entity', 42)
            ->withAttribute('_route_params', ['entity' => 42]);

        $response = $dispatcher->dispatch($route, $request);

        $this->assertSame('1', $response->getHeaderLine('X-Same-Entity'));
    }

    /**
     * Проверяем, что для entity роут-биндинг использует entity-aware registry через forEntity().
     *
     * @see ContainerHandlerResolver::resolve()
     * @see Dispatcher::dispatch()
     */
    #[Test]
    public function testEntityBindingUsesEntityManagerRegistryConnection(): void
    {
        $entity = new TenantBoundEntity(11);

        $defaultEntityManager = new DummyEntityManager([
            TenantBoundEntity::class => [],
        ]);
        $tenantEntityManager = new DummyEntityManager([
            TenantBoundEntity::class => [11 => $entity],
        ]);

        $registry = new DummyEntityManagerRegistry(
            defaultEntityManager: $defaultEntityManager,
            entityManagersByConnection: [TenantBoundEntity::class => $tenantEntityManager],
        );

        $container = new ContainerCallStub([
            'PhpSoftBox\\Orm\\Contracts\\EntityAwareEntityManagerRegistryInterface' => $registry,
            EntityManagerInterface::class                                           => $defaultEntityManager,
            EntityBindingController::class                                          => new EntityBindingController(),
        ]);

        $resolver = new ContainerHandlerResolver($container);

        $dispatcher = new Dispatcher($resolver);

        $route = new Route('GET', '/tenant-entities/{entity}', [EntityBindingController::class, 'showTenantEntity']);

        $request = new ServerRequest('GET', 'https://example.com/tenant-entities/11')
            ->withAttribute('entity', 11);

        $response = $dispatcher->dispatch($route, $request);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('11', $response->getHeaderLine('X-Entity'));
        $this->assertSame(TenantBoundEntity::class, $registry->requestedEntityClass);
    }

    /**
     * Проверяем, что #[WithDeleted] использует findWithDeleted() в репозитории.
     *
     * @see ContainerHandlerResolver::resolve()
     * @see Dispatcher::dispatch()
     */
    #[Test]
    public function testEntityBindingWithDeletedUsesRepository(): void
    {
        $entity = new DummyEntity(7);

        $repository = new DummyEntityRepository([7 => $entity]);

        $entityManager = new DummyEntityManager([
            DummyEntity::class => [7 => $entity],
        ], $repository);

        $container = new ContainerCallStub([
            EntityManagerInterface::class  => $entityManager,
            EntityBindingController::class => new EntityBindingController(),
        ]);

        $resolver = new ContainerHandlerResolver($container);

        $dispatcher = new Dispatcher($resolver);

        $route = new Route('GET', '/entities/{entity}', [EntityBindingController::class, 'showDeleted']);

        $request = new ServerRequest('GET', 'https://example.com/entities/7')
            ->withAttribute('entity', 7);

        $dispatcher->dispatch($route, $request);

        $this->assertTrue($repository->withDeletedCalled);
    }

    /**
     * Проверяем, что #[ResolveEntity] поддерживает findWithDeleted() и preload отношений.
     *
     * @see ContainerHandlerResolver::resolve()
     * @see Dispatcher::dispatch()
     */
    #[Test]
    public function testEntityBindingResolveEntityLoadsRelations(): void
    {
        $entity = new DummyEntity(8);

        $repository = new DummyEntityRepository([8 => $entity]);

        $entityManager = new DummyEntityManager([
            DummyEntity::class => [8 => $entity],
        ], $repository);

        $container = new ContainerCallStub([
            EntityManagerInterface::class  => $entityManager,
            EntityBindingController::class => new EntityBindingController(),
        ]);

        $resolver = new ContainerHandlerResolver($container);

        $dispatcher = new Dispatcher($resolver);

        $route = new Route('GET', '/entities/{entity}', [EntityBindingController::class, 'showDeletedWithRelations']);

        $request = new ServerRequest('GET', 'https://example.com/entities/8')
            ->withAttribute('entity', 8);

        $dispatcher->dispatch($route, $request);

        $this->assertTrue($repository->withDeletedCalled);
        $this->assertCount(1, $entityManager->loadCalls);
        $this->assertSame(['roles', 'profile'], $entityManager->loadCalls[0]['relations']);
        $this->assertSame($entity, $entityManager->loadCalls[0]['entity']);
    }

    /**
     * Проверяем lookup сущности по колонке с сохранением soft-delete и eager-loading настроек.
     *
     * Числовой slug намеренно остаётся строкой и не приводится к id.
     *
     * @see ContainerHandlerResolver::resolve()
     * @see Dispatcher::dispatch()
     */
    #[Test]
    public function testEntityBindingResolveEntityByColumn(): void
    {
        $entity = new DummyEntity(9);

        $entityManager = new DummyEntityManager(
            entitiesByColumn: [
                DummyEntity::class => [
                    'slug' => ['007' => $entity],
                ],
            ],
        );

        $container = new ContainerCallStub([
            EntityManagerInterface::class  => $entityManager,
            EntityBindingController::class => new EntityBindingController(),
        ]);

        $resolver = new ContainerHandlerResolver($container);

        $dispatcher = new Dispatcher($resolver);
        $route      = new Route('GET', '/entities/{entity}', [EntityBindingController::class, 'showBySlug']);
        $request    = new ServerRequest('GET', 'https://example.com/entities/007')
            ->withAttribute('entity', '007');

        $response = $dispatcher->dispatch($route, $request);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('9', $response->getHeaderLine('X-Entity'));
        $this->assertSame([
            ['entityClass' => DummyEntity::class, 'withDeleted' => true],
        ], $entityManager->queryForCalls);
        $this->assertCount(1, $entityManager->loadCalls);
        $this->assertSame(['profile'], $entityManager->loadCalls[0]['relations']);
        $this->assertSame($entity, $entityManager->loadCalls[0]['entity']);
    }

    /**
     * Проверяем, что scoped bindings учитывают кастомный resolver.
     *
     * @see ContainerHandlerResolver::resolve()
     * @see Dispatcher::dispatch()
     */
    #[Test]
    public function testScopedBindingsUsesResolver(): void
    {
        $parent = new DummyParent(1);
        $child  = new DummyChild(2);

        $entityManager = new DummyEntityManager([
            DummyParent::class => [1 => $parent],
            DummyChild::class  => [2 => $child],
        ]);

        $container = new ContainerCallStub([
            EntityManagerInterface::class          => $entityManager,
            EntityBindingController::class         => new EntityBindingController(),
            ScopedBindingsResolverInterface::class => new DummyScopedBindingsResolver(false),
        ]);

        $resolver = new ContainerHandlerResolver($container);

        $dispatcher = new Dispatcher($resolver);

        $route = new Route('GET', '/parents/{parent}/children/{child}', [EntityBindingController::class, 'scoped'], scopeBindings: true);

        $request = new ServerRequest('GET', 'https://example.com/parents/1/children/2')
            ->withAttribute('parent', 1)
            ->withAttribute('child', 2)
            ->withAttribute('_route_scope_bindings', true);

        $this->expectException(InvalidRouteParameterException::class);
        $dispatcher->dispatch($route, $request);
    }

    /**
     * Проверяем, что при конфликте атрибутов используется значение из _route_params.
     *
     * @see ContainerHandlerResolver::resolve()
     * @see Dispatcher::dispatch()
     */
    #[Test]
    public function testEntityBindingPrefersRouteParamsOverAttributes(): void
    {
        $entity = new DummyEntity(42);

        $entityManager = new DummyEntityManager([
            DummyEntity::class => [42 => $entity, 99 => new DummyEntity(99)],
        ]);

        $container = new ContainerCallStub([
            EntityManagerInterface::class  => $entityManager,
            EntityBindingController::class => new EntityBindingController(),
        ]);

        $resolver = new ContainerHandlerResolver($container);

        $dispatcher = new Dispatcher($resolver);

        $route = new Route('GET', '/entities/{entity}', [EntityBindingController::class, 'show']);

        $request = new ServerRequest('GET', 'https://example.com/entities/42')
            ->withAttribute('entity', 99)
            ->withAttribute('_route_params', ['entity' => 42]);

        $response = $dispatcher->dispatch($route, $request);

        $this->assertSame('42', $response->getHeaderLine('X-Entity'));
    }
}
