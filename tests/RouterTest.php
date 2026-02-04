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
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\CoversMethod;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;

#[CoversClass(Router::class)]
#[CoversMethod(Router::class, 'handle')]
#[CoversClass(RouteResolver::class)]
#[CoversMethod(RouteResolver::class, 'resolve')]
#[CoversClass(RouteCollector::class)]
#[CoversMethod(RouteCollector::class, 'get')]
#[CoversMethod(RouteCollector::class, 'scopeBindings')]
final class RouterTest extends TestCase
{
    private function makeRouter(RouteCollector $rc): Router
    {
        return new Router(new RouteResolver($rc), new Dispatcher(), $rc);
    }

    /**
     * Проверяет успешную обработку запроса найденным маршрутом.
     *
     * @see Router::handle()
     * @see RouteResolver::resolve()
     */
    #[Test]
    public function testHandleSuccess(): void
    {
        $rc = new RouteCollector();

        $rc->get('/hello', fn (ServerRequestInterface $r) => new Response(200, [], 'OK'));
        $router = $this->makeRouter($rc);

        $resp = $router->handle(new ServerRequest('GET', 'https://example.com/hello'));

        $this->assertSame(200, $resp->getStatusCode());
        $this->assertSame('OK', (string) $resp->getBody());
    }

    /**
     * Проверяет, что при отсутствии маршрута выбрасывается RouteNotFoundException.
     *
     * @see Router::handle()
     * @see RouteResolver::resolve()
     */
    #[Test]
    public function testHandleNotFound(): void
    {
        $router = $this->makeRouter(new RouteCollector());

        $this->expectException(RouteNotFoundException::class);
        $router->handle(new ServerRequest('GET', 'https://example.com/missing'));
    }

    /**
     * Проверяем, что параметры маршрута и defaults попадают в attributes запроса.
     *
     * @see Router::handle()
     * @see RouteCollector::get()
     */
    #[Test]
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
        })->name('users.show')->defaults(['extra' => 'default']);

        $router = $this->makeRouter($rc);

        $resp = $router->handle(new ServerRequest('GET', 'https://example.com/users/42'));

        $this->assertSame('42', $resp->getHeaderLine('X-Id'));
        $this->assertSame('default', $resp->getHeaderLine('X-Extra'));
        $this->assertSame('users.show', $resp->getHeaderLine('X-Route'));
        $this->assertSame('42', $resp->getHeaderLine('X-Params'));
    }

    /**
     * Проверяет, что флаг scope bindings попадает в attributes запроса.
     *
     * @see Router::handle()
     * @see RouteCollector::scopeBindings()
     */
    #[Test]
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
     *
     * @see Router::handle()
     * @see RouteResolver::resolve()
     */
    #[Test]
    public function testInvalidParamFallsThroughToNextRoute(): void
    {
        $rc = new RouteCollector();

        $rc->get('/users/{id}', fn (ServerRequestInterface $r) => new Response(200))->validators(['id' => ParamTypesEnum::INT]);
        $rc->get('/users/create', fn (ServerRequestInterface $r) => new Response(201));

        $router = $this->makeRouter($rc);

        $this->expectException(InvalidRouteParameterException::class);
        $this->expectExceptionMessage('Invalid parameter: id');
        $router->handle(new ServerRequest('GET', 'https://example.com/users/create'));
    }

}
