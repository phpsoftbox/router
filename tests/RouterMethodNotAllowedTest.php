<?php

declare(strict_types=1);

namespace PhpSoftBox\Router\Tests;

use PhpSoftBox\Http\Message\ServerRequest;
use PhpSoftBox\Router\Dispatcher;
use PhpSoftBox\Router\Exception\MethodNotAllowedException;
use PhpSoftBox\Router\RouteCollector;
use PhpSoftBox\Router\Router;
use PhpSoftBox\Router\RouteResolver;
use PHPUnit\Framework\TestCase;

final class RouterMethodNotAllowedTest extends TestCase
{
    /**
     * Проверяем, что при несовпадении метода выбрасывается исключение MethodNotAllowedException.
     */
    public function testMethodNotAllowed(): void
    {
        $collector = new RouteCollector();

        $collector->get('/users', fn () => null);
        $collector->post('/users', fn () => null);

        $router = new Router(new RouteResolver($collector), new Dispatcher(), $collector);

        $this->expectException(MethodNotAllowedException::class);
        $router->handle(new ServerRequest('PUT', 'https://example.com/users'));
    }
}
