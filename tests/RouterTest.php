<?php

declare(strict_types=1);

namespace PhpSoftBox\Router\Tests;

use PhpSoftBox\Http\Message\Response;
use PhpSoftBox\Http\Message\ServerRequest;
use PhpSoftBox\Orm\Contracts\EntityInterface;
use PhpSoftBox\Router\Dispatcher;
use PhpSoftBox\Router\Exception\InvalidRouteParameterException;
use PhpSoftBox\Router\Exception\RouteNotFoundException;
use PhpSoftBox\Router\ParamTypesEnum;
use PhpSoftBox\Router\RouteCollector;
use PhpSoftBox\Router\Router;
use PhpSoftBox\Router\RouteResolver;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;

use function interface_exists;

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

    public function testScopeBindingsAttributeIsSet(): void
    {
        $rc = new RouteCollector();

        $rc->scopeBindings(static function (RouteCollector $routes): void {
            $routes->get('/users/{user}/companies/{company}', static function (ServerRequestInterface $r): Response {
                return new Response(200, [
                    'X-Scoped' => $r->getAttribute('_route_scope_bindings') ? '1' : '0',
                ]);
            });
        });

        $router = $this->makeRouter($rc);

        $resp = $router->handle(new ServerRequest('GET', 'https://example.com/users/1/companies/2'));

        $this->assertSame('1', $resp->getHeaderLine('X-Scoped'));
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

    public function testUrlForSupportsOrmEntityParam(): void
    {
        $entityInterface = 'PhpSoftBox\\Orm\\Contracts\\EntityInterface';
        if (!interface_exists($entityInterface)) {
            $this->markTestSkipped('ORM is not installed.');
        }

        $rc = new RouteCollector();

        $rc->get('/users/{user}', fn (ServerRequestInterface $r) => new Response(200), name: 'user.show');
        $router = $this->makeRouter($rc);

        $entity = new class () implements EntityInterface {
            public function id(): int|null
            {
                return 42;
            }
        };

        $this->assertSame('/users/42', $router->urlFor('user.show', ['user' => $entity]));
    }

    public function testUrlForThrowsWhenOrmEntityHasNoId(): void
    {
        $entityInterface = 'PhpSoftBox\\Orm\\Contracts\\EntityInterface';
        if (!interface_exists($entityInterface)) {
            $this->markTestSkipped('ORM is not installed.');
        }

        $rc = new RouteCollector();

        $rc->get('/users/{user}', fn (ServerRequestInterface $r) => new Response(200), name: 'user.show');
        $router = $this->makeRouter($rc);

        $entity = new class () implements EntityInterface {
            public function id(): int|null
            {
                return null;
            }
        };

        $this->expectException(RouteNotFoundException::class);
        $this->expectExceptionMessage('entity parameter');
        $router->urlFor('user.show', ['user' => $entity]);
    }

    public function testUrlForRouteNameNotFound(): void
    {
        $router = $this->makeRouter(new RouteCollector());

        $this->expectException(RouteNotFoundException::class);
        $router->urlFor('user.show');
    }
}
