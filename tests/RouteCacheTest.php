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
use PHPUnit\Framework\TestCase;

final class RouteCacheTest extends TestCase
{
    /**
     * Проверяем, что кеш маршрутов сохраняется и загружается.
     */
    public function testDumpAndLoad(): void
    {
        $collector = new RouteCollector();

        $collector->get('/ping/{id}', InvokableController::class, validators: ['id' => ParamTypesEnum::INT]);

        $cache = new RouteCache(new ArrayCache());

        $cache->dump($collector, 'dev');

        $loaded = $cache->load('dev');
        $router = new Router(new RouteResolver($loaded), new Dispatcher(), $loaded);

        $response = $router->handle(new ServerRequest('GET', 'https://example.com/ping/10'));

        $this->assertSame(202, $response->getStatusCode());
    }

    /**
     * Проверяем, что кеш маршрутов не поддерживает замыкания.
     */
    public function testDumpThrowsOnClosure(): void
    {
        $collector = new RouteCollector();

        $collector->get('/closure', fn () => new Response(200));

        $this->expectException(RouteCacheException::class);
        new RouteCache(new ArrayCache())->dump($collector, 'dev');
    }
}
