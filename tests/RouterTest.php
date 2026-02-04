<?php

declare(strict_types=1);

namespace PhpSoftBox\Router\Tests;

use PhpSoftBox\Http\Message\Response;
use PhpSoftBox\Http\Message\ServerRequest;
use PhpSoftBox\Router\Dispatcher;
use PhpSoftBox\Router\Exception\InvalidRouteParameterException;
use PhpSoftBox\Router\Exception\RouteNotFoundException;
use PhpSoftBox\Router\ParamTypesEnum;
use PhpSoftBox\Router\RouteCollector;
use PhpSoftBox\Router\Router;
use PhpSoftBox\Router\RouteResolver;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;

final class RouterTest extends TestCase
{
    private function makeRouter(RouteCollector $rc): Router
    {
        return new Router(new RouteResolver($rc), new Dispatcher(), $rc);
    }

    public function testHandleSuccess(): void
    {
        $rc = new RouteCollector();

        $rc->get('/hello', fn (ServerRequestInterface $r) => new Response(200, [], 'OK'));
        $router = $this->makeRouter($rc);

        $resp = $router->handle(new ServerRequest('GET', 'https://example.com/hello'));

        $this->assertSame(200, $resp->getStatusCode());
        $this->assertSame('OK', (string) $resp->getBody());
    }

    public function testHandleNotFound(): void
    {
        $router = $this->makeRouter(new RouteCollector());

        $this->expectException(RouteNotFoundException::class);
        $router->handle(new ServerRequest('GET', 'https://example.com/missing'));
    }

    /**
     * Проверяем, что параметры маршрута и defaults попадают в attributes запроса.
     */
    public function testRouteParamsAreAddedToAttributes(): void
    {
        $rc = new RouteCollector();

        $rc->get('/users/{id}/{extra?}', function (ServerRequestInterface $r): Response {
            return new Response(200, [
                'X-Id'     => (string) $r->getAttribute('id'),
                'X-Extra'  => (string) $r->getAttribute('extra'),
                'X-Route'  => (string) $r->getAttribute('_route'),
                'X-Params' => (string) ($r->getAttribute('_route_params')['id'] ?? ''),
            ]);
        }, name: 'users.show', defaults: ['extra' => 'default']);

        $router = $this->makeRouter($rc);

        $resp = $router->handle(new ServerRequest('GET', 'https://example.com/users/42'));

        $this->assertSame('42', $resp->getHeaderLine('X-Id'));
        $this->assertSame('default', $resp->getHeaderLine('X-Extra'));
        $this->assertSame('users.show', $resp->getHeaderLine('X-Route'));
        $this->assertSame('42', $resp->getHeaderLine('X-Params'));
    }

    /**
     * Проверяем, что невалидный параметр приводит к исключению, даже если есть более специфичный маршрут.
     */
    public function testInvalidParamFallsThroughToNextRoute(): void
    {
        $rc = new RouteCollector();

        $rc->get('/users/{id}', fn (ServerRequestInterface $r) => new Response(200), validators: ['id' => ParamTypesEnum::INT]);
        $rc->get('/users/create', fn (ServerRequestInterface $r) => new Response(201));

        $router = $this->makeRouter($rc);

        $this->expectException(InvalidRouteParameterException::class);
        $this->expectExceptionMessage('Invalid parameter: id');
        $router->handle(new ServerRequest('GET', 'https://example.com/users/create'));
    }

    public function testUrlForRequiredAndOptionalParams(): void
    {
        $rc = new RouteCollector();

        $rc->get('/users/{id}/{extra?}', fn (ServerRequestInterface $r) => new Response(200), name: 'user.show');
        $router = $this->makeRouter($rc);

        $this->assertSame('/users/42', $router->urlFor('user.show', ['id' => 42]));
        $this->assertSame('/users/42/foo', $router->urlFor('user.show', ['id' => 42, 'extra' => 'foo']));
    }

    public function testUrlForMissingRequiredParam(): void
    {
        $rc = new RouteCollector();

        $rc->get('/users/{id}', fn (ServerRequestInterface $r) => new Response(200), name: 'user.show');
        $router = $this->makeRouter($rc);

        $this->expectException(RouteNotFoundException::class);
        $router->urlFor('user.show');
    }

    public function testUrlForRouteNameNotFound(): void
    {
        $router = $this->makeRouter(new RouteCollector());

        $this->expectException(RouteNotFoundException::class);
        $router->urlFor('user.show');
    }
}
