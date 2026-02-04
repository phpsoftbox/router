<?php

declare(strict_types=1);

namespace PhpSoftBox\Router\Tests;

use PhpSoftBox\Http\Message\Response;
use PhpSoftBox\Http\Message\ServerRequest;
use PhpSoftBox\Router\Cache\RouteCache;
use PhpSoftBox\Router\Dispatcher;
use PhpSoftBox\Router\Exception\RouteCacheException;
use PhpSoftBox\Router\ParamTypesEnum;
use PhpSoftBox\Router\RouteCollector;
use PhpSoftBox\Router\Router;
use PhpSoftBox\Router\RouteResolver;
use PhpSoftBox\Router\Tests\Fixtures\ArrayCache;
use PhpSoftBox\Router\Tests\Fixtures\InvokableController;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\CoversMethod;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(RouteCache::class)]
#[CoversMethod(RouteCache::class, 'dump')]
#[CoversMethod(RouteCache::class, 'load')]
final class RouteCacheTest extends TestCase
{
    /**
     * Проверяем, что кеш маршрутов сохраняется и загружается.
     *
     * @see RouteCache::dump()
     * @see RouteCache::load()
     */
    #[Test]
    public function testDumpAndLoad(): void
    {
        $collector = new RouteCollector();

        $collector->get('/ping/{id}', InvokableController::class)->validators(['id' => ParamTypesEnum::INT]);

        $cache = new RouteCache(new ArrayCache());

        $cache->dump($collector, 'dev');

        $loaded = $cache->load('dev');
        $router = new Router(new RouteResolver($loaded), new Dispatcher(), $loaded);

        $response = $router->handle(new ServerRequest('GET', 'https://example.com/ping/10'));

        $this->assertSame(202, $response->getStatusCode());
    }

    /**
     * Проверяем, что кеш маршрутов не поддерживает замыкания.
     *
     * @see RouteCache::dump()
     */
    #[Test]
    public function testDumpThrowsOnClosure(): void
    {
        $collector = new RouteCollector();

        $collector->get('/closure', fn () => new Response(200));

        $this->expectException(RouteCacheException::class);
        new RouteCache(new ArrayCache())->dump($collector, 'dev');
    }

    /**
     * Проверяем, что флаг scopeBindings сохраняется и восстанавливается из кеша.
     *
     * @see RouteCache::dump()
     * @see RouteCache::load()
     */
    #[Test]
    public function testDumpAndLoadKeepsScopeBindings(): void
    {
        $collector = new RouteCollector();

        $collector->get('/scoped/{id}', InvokableController::class)->scopeBindings();

        $cache = new RouteCache(new ArrayCache());

        $cache->dump($collector, 'dev');

        $loaded = $cache->load('dev');
        $routes = $loaded->getRoutes();

        $this->assertCount(1, $routes);
        $this->assertTrue($routes[0]->scopeBindings);
    }

    /**
     * Проверяем, что список host сохраняется и применяется после загрузки из кеша.
     *
     * @see RouteCache::dump()
     * @see RouteCache::load()
     */
    #[Test]
    public function testDumpAndLoadKeepsHosts(): void
    {
        $collector = new RouteCollector();

        $collector->get('/ping/{id}', InvokableController::class)
            ->host(['dispatcher.example.com', 'dispatcher-mirror.example.com']);

        $cache = new RouteCache(new ArrayCache());

        $cache->dump($collector, 'dev');

        $loaded = $cache->load('dev');
        $router = new Router(new RouteResolver($loaded), new Dispatcher(), $loaded);

        $response = $router->handle(new ServerRequest('GET', 'https://dispatcher-mirror.example.com/ping/10'));

        $this->assertSame(202, $response->getStatusCode());
    }

    /**
     * Проверяем, что wildcard-маршрут сохраняется и применяется после загрузки из кеша.
     *
     * @see RouteCache::dump()
     * @see RouteCache::load()
     */
    #[Test]
    public function testDumpAndLoadKeepsWildcardRoute(): void
    {
        $collector = new RouteCollector();

        $collector->get('/docs/{path*}', InvokableController::class);

        $cache = new RouteCache(new ArrayCache());

        $cache->dump($collector, 'dev');

        $loaded = $cache->load('dev');
        $router = new Router(new RouteResolver($loaded), new Dispatcher(), $loaded);

        $response = $router->handle(new ServerRequest('GET', 'https://example.com/docs/a/b'));

        $this->assertSame(202, $response->getStatusCode());
    }
}
