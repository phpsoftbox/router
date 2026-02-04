<?php

declare(strict_types=1);

namespace PhpSoftBox\Router\Tests;

use PhpSoftBox\Http\Message\Response;
use PhpSoftBox\Http\Message\ServerRequest;
use PhpSoftBox\Router\Dispatcher;
use PhpSoftBox\Router\Route;
use PhpSoftBox\Router\Tests\Utils\DummyController;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

use function trim;

final class DispatcherTest extends TestCase
{
    public function testDispatchClosureHandler(): void
    {
        $route = new Route('GET', '/hi', function (ServerRequestInterface $r) {
            return new Response(200, [], 'OK');
        });
        $resp = new Dispatcher()->dispatch($route, new ServerRequest('GET', 'https://example.com/hi'));

        $this->assertSame(200, $resp->getStatusCode());
        $this->assertSame('OK', (string) $resp->getBody());
    }

    public function testDispatchControllerHandler(): void
    {
        $route = new Route('GET', '/hi', [new DummyController(), 'hello']);

        $resp = new Dispatcher()->dispatch($route, new ServerRequest('GET', 'https://example.com/hi'));

        $this->assertSame(201, $resp->getStatusCode());
    }

    public function testMiddlewareOrder(): void
    {
        $mw1 = new class () implements MiddlewareInterface {
            public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): \Psr\Http\Message\ResponseInterface
            {
                $resp = $handler->handle($request);

                return $resp->withHeader('X', trim($resp->getHeaderLine('X') . ' A'));
            }
        };
        $mw2 = new class () implements MiddlewareInterface {
            public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): \Psr\Http\Message\ResponseInterface
            {
                $resp = $handler->handle($request);

                return $resp->withHeader('X', trim($resp->getHeaderLine('X') . ' B'));
            }
        };
        $route = new Route('GET', '/x', fn (ServerRequestInterface $r) => new Response(200, ['X' => 'H']), middlewares: [$mw1, $mw2]);

        $resp = new Dispatcher()->dispatch($route, new ServerRequest('GET', 'https://example.com/x'));

        $this->assertSame('H B A', $resp->getHeaderLine('X'));
    }
}
