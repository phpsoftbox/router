<?php

declare(strict_types=1);

namespace PhpSoftBox\Router\Tests\Profiler;

use PhpSoftBox\Http\Message\Response;
use PhpSoftBox\Http\Message\ServerRequest;
use PhpSoftBox\Profiler\Profiler;
use PhpSoftBox\Profiler\ProfileTrace;
use PhpSoftBox\Router\Dispatcher;
use PhpSoftBox\Router\Exception\RouteNotFoundException;
use PhpSoftBox\Router\Profiler\RouterProfilerCollector;
use PhpSoftBox\Router\RouteCollector;
use PhpSoftBox\Router\Router;
use PhpSoftBox\Router\RouteResolver;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\CoversMethod;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(Router::class)]
#[CoversClass(Dispatcher::class)]
#[CoversMethod(Router::class, 'handle')]
#[CoversMethod(Dispatcher::class, 'dispatch')]
final class RouterProfilerCollectorOutsideTraceTest extends TestCase
{
    /**
     * Проверим, что при выключенном профайлере match и dispatch не записываются.
     *
     * @see Router::handle()
     * @see Dispatcher::dispatch()
     */
    #[Test]
    public function disabledProfilerDoesNotRecordRoutes(): void
    {
        $collector = new RouterProfilerCollector();

        $this->router(new Profiler(enabled: false), $collector)
            ->handle(new ServerRequest('GET', 'https://example.test/hello'));

        self::assertSame([], $this->collect($collector)['routes']);
    }

    /**
     * Проверим, что при выключенном профайлере «маршрут не найден» не записывается.
     *
     * @see Router::handle()
     */
    #[Test]
    public function disabledProfilerDoesNotRecordNotFound(): void
    {
        $collector = new RouterProfilerCollector();

        try {
            $this->router(new Profiler(enabled: false), $collector)
                ->handle(new ServerRequest('GET', 'https://example.test/missing'));
        } catch (RouteNotFoundException) {
            // Ожидаемо: проверяем только коллектор.
        }

        self::assertSame(0, $this->collect($collector)['not_found']);
    }

    /**
     * Проверим, что внутри трассы match и dispatch записываются.
     *
     * @see Router::handle()
     * @see Dispatcher::dispatch()
     */
    #[Test]
    public function activeTraceRecordsRoutes(): void
    {
        $collector = new RouterProfilerCollector();
        $profiler  = new Profiler();

        $profiler->startTrace('test.request', 'http');

        $this->router($profiler, $collector)->handle(new ServerRequest('GET', 'https://example.test/hello'));

        $data = $this->collect($collector);

        self::assertSame(1, $data['matches']);
        self::assertSame(1, $data['dispatches']);
    }

    private function router(Profiler $profiler, RouterProfilerCollector $collector): Router
    {
        $routes = new RouteCollector();

        $routes->get('/hello', static fn (): Response => new Response(200, [], 'OK'));

        return new Router(
            new RouteResolver($routes),
            new Dispatcher(profiler: $profiler, profilerCollector: $collector),
            $routes,
            $profiler,
            $collector,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function collect(RouterProfilerCollector $collector): array
    {
        return $collector->collect(new ProfileTrace('id', 'test', 'test'));
    }
}
