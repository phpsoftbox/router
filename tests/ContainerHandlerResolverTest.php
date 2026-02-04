<?php

declare(strict_types=1);

namespace PhpSoftBox\Router\Tests;

use PhpSoftBox\Http\Message\ServerRequest;
use PhpSoftBox\Router\Dispatcher;
use PhpSoftBox\Router\Handler\ContainerHandlerResolver;
use PhpSoftBox\Router\Route;
use PhpSoftBox\Router\Tests\Fixtures\RequestAwareController;
use PhpSoftBox\Router\Tests\Fixtures\RequestSchemaController;
use PhpSoftBox\Router\Tests\Fixtures\RouteParamController;
use PhpSoftBox\Router\Tests\Utils\ContainerCallStub;
use PhpSoftBox\Router\Tests\Utils\ContainerStub;
use PhpSoftBox\Router\Tests\Utils\DummyController;
use PhpSoftBox\Validator\Exception\ValidationException;
use PhpSoftBox\Validator\Validator;
use PhpSoftBox\Validator\ValidatorInterface;
use PHPUnit\Framework\TestCase;

final class ContainerHandlerResolverTest extends TestCase
{
    /**
     * Проверяем, что ContainerHandlerResolver берёт контроллер из контейнера.
     */
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
     * Проверяем, что при наличии container->call используется инъекция параметров.
     */
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
     */
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
     */
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
     * Проверяем, что RequestSchema бросает ValidationException при ошибке валидации.
     */
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
}
