<?php

declare(strict_types=1);

namespace PhpSoftBox\Router\Tests;

use PhpSoftBox\Http\Message\ServerRequest;
use PhpSoftBox\Router\Dispatcher;
use PhpSoftBox\Router\Route;
use PhpSoftBox\Router\Tests\Fixtures\InvokableController;
use PhpSoftBox\Router\Tests\Utils\DummyController;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\CoversMethod;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;

#[CoversClass(Dispatcher::class)]
#[CoversMethod(Dispatcher::class, 'dispatch')]
final class DispatcherResolveHandlerTest extends TestCase
{
    /**
     * Проверяет, что handler в виде [ClassString, method] создаётся и вызывается.
     *
     * @see Dispatcher::dispatch()
     */
    #[Test]
    public function resolvesClassStringHandler(): void
    {
        $route = new Route('GET', '/hi', [DummyController::class, 'hello']);

        $resp = new Dispatcher()->dispatch($route, new ServerRequest('GET', 'https://example.com/hi'));

        $this->assertSame(201, $resp->getStatusCode());
    }

    /**
     * Проверяет, что invokable-класс можно использовать как handler.
     *
     * @see Dispatcher::dispatch()
     */
    #[Test]
    public function resolvesInvokableHandler(): void
    {
        $route = new Route('GET', '/ping', InvokableController::class);

        $resp = new Dispatcher()->dispatch($route, new ServerRequest('GET', 'https://example.com/ping'));

        $this->assertSame(202, $resp->getStatusCode());
        $this->assertSame('1', $resp->getHeaderLine('X-Invoked'));
    }

    /**
     * Проверяет, что невалидный handler приводит к RuntimeException.
     *
     * @see Dispatcher::dispatch()
     */
    #[Test]
    public function invalidHandlerThrowsRuntime(): void
    {
        $route = new Route('GET', '/bad', ['Not\\Existing\\Class', 'method']);

        $this->expectException(RuntimeException::class);
        new Dispatcher()->dispatch($route, new ServerRequest('GET', 'https://example.com/bad'));
    }
}
