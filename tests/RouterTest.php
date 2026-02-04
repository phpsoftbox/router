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
use PhpSoftBox\Router\RequestContext;
use PhpSoftBox\Router\RouteCollector;
use PhpSoftBox\Router\Router;
use PhpSoftBox\Router\RouteResolver;
use PhpSoftBox\Router\UrlGenerator;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;

use function interface_exists;

final class RouterTest extends TestCase
{
    private function makeRouter(RouteCollector $rc): Router
    {
        return new Router(new RouteResolver($rc), new Dispatcher(), $rc);
    }

    private function makeUrlGenerator(RouteCollector $rc): UrlGenerator
    {
        return new UrlGenerator($rc);
    }

    /**
     * Проверяет успешную обработку запроса найденным маршрутом.
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

    /**
     * Проверяет подстановку обязательных и опциональных параметров в URL.
     */
    #[Test]
    public function testUrlForRequiredAndOptionalParams(): void
    {
        $rc = new RouteCollector();

        $rc->get('/users/{id}/{extra?}', fn (ServerRequestInterface $r) => new Response(200))->name('user.show');
        $urlGenerator = $this->makeUrlGenerator($rc);

        $this->assertSame('/users/42', $urlGenerator->generate('user.show', ['id' => 42]));
        $this->assertSame('/users/42/foo', $urlGenerator->generate('user.show', ['id' => 42, 'extra' => 'foo']));
    }

    /**
     * Проверяет генерацию абсолютного URL по host маршрута без схемы.
     */
    #[Test]
    public function testUrlForCanReturnAbsoluteUrlWithRouteHost(): void
    {
        $rc = new RouteCollector();

        $rc->get('/users/{id}', fn (ServerRequestInterface $r) => new Response(200))->name('user.show')->host('admin.example.com');
        $urlGenerator = $this->makeUrlGenerator($rc);

        $this->assertSame('https://admin.example.com/users/42', $urlGenerator->generate('user.show', ['id' => 42], true));
    }

    /**
     * Проверяет генерацию абсолютного URL по host маршрута со схемой.
     */
    #[Test]
    public function testUrlForCanReturnAbsoluteUrlWithHostScheme(): void
    {
        $rc = new RouteCollector();

        $rc->get('/users/{id}', fn (ServerRequestInterface $r) => new Response(200))->name('user.show')->host('http://admin.example.com');
        $urlGenerator = $this->makeUrlGenerator($rc);

        $this->assertSame('http://admin.example.com/users/42', $urlGenerator->generate('user.show', ['id' => 42], true));
    }

    /**
     * Проверяет приоритет host из RequestContext над host маршрута.
     */
    #[Test]
    public function testUrlGeneratorUsesRequestContextHost(): void
    {
        $rc = new RouteCollector();

        $rc->get('/users/{id}', fn (ServerRequestInterface $r) => new Response(200))->name('user.show')->host('admin.example.com');
        $context = new RequestContext('https', 'runtime.example.com', null);

        $urlGenerator = new UrlGenerator($rc, context: $context);

        $this->assertSame('https://runtime.example.com/users/42', $urlGenerator->generate('user.show', ['id' => 42], true));
    }

    /**
     * Проверяет нормализацию абсолютного URL при runtime-схеме http и порте 443.
     */
    #[Test]
    public function testUrlGeneratorNormalizesHttpWithPort443ToHttps(): void
    {
        $rc = new RouteCollector();

        $rc->get('/users/{id}', fn (ServerRequestInterface $r) => new Response(200))->name('user.show')->host('admin.example.com');
        $context = new RequestContext('http', 'runtime.example.com', 443);

        $urlGenerator = new UrlGenerator($rc, context: $context);

        $this->assertSame('https://runtime.example.com/users/42', $urlGenerator->generate('user.show', ['id' => 42], true));
    }

    /**
     * Проверяет, что RequestContext из запроса с http:443 нормализуется в https без порта.
     */
    #[Test]
    public function testUrlGeneratorNormalizesRequestContextFromHttpPort443(): void
    {
        $rc = new RouteCollector();

        $rc->get('/users/{id}', fn (ServerRequestInterface $r) => new Response(200))->name('user.show');
        $request = new ServerRequest('GET', 'http://dispatcher.example.com:443/users');

        $urlGenerator = new UrlGenerator($rc, request: $request);

        $this->assertSame('https://dispatcher.example.com/users/42', $urlGenerator->generate('user.show', ['id' => 42], true));
    }

    /**
     * Проверяет возврат относительного URL, когда host отсутствует.
     */
    #[Test]
    public function testUrlForReturnsRelativePathWhenHostIsMissing(): void
    {
        $rc = new RouteCollector();

        $rc->get('/users/{id}', fn (ServerRequestInterface $r) => new Response(200))->name('user.show');
        $urlGenerator = $this->makeUrlGenerator($rc);

        $this->assertSame('/users/42', $urlGenerator->generate('user.show', ['id' => 42], true));
    }

    /**
     * Проверяет ошибку при отсутствии обязательного параметра маршрута.
     */
    #[Test]
    public function testUrlForMissingRequiredParam(): void
    {
        $rc = new RouteCollector();

        $rc->get('/users/{id}', fn (ServerRequestInterface $r) => new Response(200))->name('user.show');
        $urlGenerator = $this->makeUrlGenerator($rc);

        $this->expectException(RouteNotFoundException::class);
        $urlGenerator->generate('user.show');
    }

    /**
     * Проверяет поддержку ORM-сущности как параметра URL.
     */
    #[Test]
    public function testUrlForSupportsOrmEntityParam(): void
    {
        $entityInterface = 'PhpSoftBox\\Orm\\Contracts\\EntityInterface';
        if (!interface_exists($entityInterface)) {
            $this->markTestSkipped('ORM is not installed.');
        }

        $rc = new RouteCollector();

        $rc->get('/users/{user}', fn (ServerRequestInterface $r) => new Response(200))->name('user.show');
        $urlGenerator = $this->makeUrlGenerator($rc);

        $entity = new class () implements EntityInterface {
            public function id(): int|null
            {
                return 42;
            }
        };

        $this->assertSame('/users/42', $urlGenerator->generate('user.show', ['user' => $entity]));
    }

    /**
     * Проверяет ошибку при передаче ORM-сущности без id.
     */
    #[Test]
    public function testUrlForThrowsWhenOrmEntityHasNoId(): void
    {
        $entityInterface = 'PhpSoftBox\\Orm\\Contracts\\EntityInterface';
        if (!interface_exists($entityInterface)) {
            $this->markTestSkipped('ORM is not installed.');
        }

        $rc = new RouteCollector();

        $rc->get('/users/{user}', fn (ServerRequestInterface $r) => new Response(200))->name('user.show');
        $urlGenerator = $this->makeUrlGenerator($rc);

        $entity = new class () implements EntityInterface {
            public function id(): int|null
            {
                return null;
            }
        };

        $this->expectException(RouteNotFoundException::class);
        $this->expectExceptionMessage('entity parameter');
        $urlGenerator->generate('user.show', ['user' => $entity]);
    }

    /**
     * Проверяет ошибку при запросе URL для несуществующего имени маршрута.
     */
    #[Test]
    public function testUrlForRouteNameNotFound(): void
    {
        $urlGenerator = $this->makeUrlGenerator(new RouteCollector());

        $this->expectException(RouteNotFoundException::class);
        $urlGenerator->generate('user.show');
    }
}
