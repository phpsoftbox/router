<?php

declare(strict_types=1);

namespace PhpSoftBox\Router\Tests;

use PhpSoftBox\Http\Message\Response;
use PhpSoftBox\Http\Message\ServerRequest;
use PhpSoftBox\Router\RouteCollector;
use PhpSoftBox\Router\Tests\Utils\DummyController;
use PhpSoftBox\Router\Tests\Utils\HeaderAppendMiddleware;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use RuntimeException;

use function array_shift;
use function file_put_contents;
use function mkdir;
use function rmdir;
use function sys_get_temp_dir;
use function uniqid;
use function unlink;

final class RouteCollectorTest extends TestCase
{
    public function testNamedRoutesAndBasicAdd(): void
    {
        $rc = new RouteCollector();

        $rc->get('/users/{id}', function (ServerRequestInterface $r) {
            return new Response(200);
        })->name('user.show');

        $routes = $rc->getRoutes();
        $this->assertCount(1, $routes);

        $named = $rc->getNamedRoutes();
        $this->assertArrayHasKey('user.show', $named);
        $this->assertSame('/users/{id}', $named['user.show']->path);
    }

    public function testMiddlewareMergingOrderGlobalGroupLocal(): void
    {
        $rc = new RouteCollector();

        // Глобальный
        $rc->addMiddleware(new HeaderAppendMiddleware('global', 'X-Order'));

        $rc->group(function (RouteCollector $r) {
            // Локальный для маршрута
            $r->get('/users', function (ServerRequestInterface $req) {
                return new Response(200, ['X-Order' => 'H']);
            })->middlewares([new HeaderAppendMiddleware('route', 'X-Order')]);
        })
            ->prefix('/api')
            ->middlewares([
                // Групповой
                new HeaderAppendMiddleware('group', 'X-Order'),
            ])
            ->apply();

        $routes = $rc->getRoutes();
        $this->assertCount(1, $routes);

        // Прогоним стек middleware вручную через handler, чтобы проверить порядок
        $route   = $routes[0];
        $handler = new class ($route->handler, $route->middlewares) implements RequestHandlerInterface {
            private $handler;
            private array $mw;
            public function __construct(
                $h,
                array $mw,
            ) {
                $this->handler = $h;
                $this->mw      = $mw;
            }
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                if (empty($this->mw)) {
                    $h = $this->handler;

                    return $h($request);
                }
                /** @var MiddlewareInterface $m */
                $m = array_shift($this->mw);

                return $m->process($request, $this);
            }
        };

        $resp = $handler->handle(new ServerRequest('GET', 'https://example.com/api/users'));

        $this->assertSame('H-route-group-global', $resp->getHeaderLine('X-Order'));
    }

    public function testAutoRouteNames(): void
    {
        $rc = new RouteCollector();

        $rc->get('/users', static fn (): Response => new Response(200));
        $rc->post('/users', static fn (): Response => new Response(200));
        $rc->get('/users/{id}', static fn (): Response => new Response(200));
        $rc->put('/users/{id}', static fn (): Response => new Response(200));
        $rc->delete('/users/{id}', static fn (): Response => new Response(200));
        $rc->get('/api/crm/orders/{id}', static fn (): Response => new Response(200));

        $named = $rc->getNamedRoutes();
        $this->assertArrayHasKey('users.index', $named);
        $this->assertArrayHasKey('users.store', $named);
        $this->assertArrayHasKey('users.show', $named);
        $this->assertArrayHasKey('users.update', $named);
        $this->assertArrayHasKey('users.destroy', $named);
        $this->assertArrayHasKey('api.crm.orders.show', $named);
    }

    public function testAutoRouteNamesIncludeIntermediatePathParams(): void
    {
        $rc = new RouteCollector();

        $rc->post('/api/accounts/refresh', static fn (): Response => new Response(200));
        $rc->post('/api/accounts/{id}/refresh', static fn (): Response => new Response(200));

        $named = $rc->getNamedRoutes();
        $this->assertArrayHasKey('api.accounts.refresh.store', $named);
        $this->assertArrayHasKey('api.accounts.by-id.refresh.store', $named);
    }

    public function testExplicitNameCanBeAppliedForRouteWithIntermediatePathParam(): void
    {
        $rc = new RouteCollector();

        $rc->post('/api/accounts/refresh', static fn (): Response => new Response(200));
        $rc->post('/api/accounts/{id}/refresh', static fn (): Response => new Response(200))
            ->name('api.accounts.refresh-by-id.store');

        $named = $rc->getNamedRoutes();
        $this->assertArrayHasKey('api.accounts.refresh.store', $named);
        $this->assertArrayHasKey('api.accounts.refresh-by-id.store', $named);
    }

    public function testAutoRouteNamesIncludeGroupPrefix(): void
    {
        $rc = new RouteCollector();

        $rc->group(function (RouteCollector $routes): void {
            $routes->get('/users', static fn (): Response => new Response(200));
        })->prefix('/api')->apply();

        $named = $rc->getNamedRoutes();
        $this->assertArrayHasKey('api.users.index', $named);
    }

    public function testGroupNamePrefixAppliesToAutoNames(): void
    {
        $rc = new RouteCollector();

        $rc->group(function (RouteCollector $routes): void {
            $routes->get('/login', static fn (): Response => new Response(200));
        })->namePrefix('admin')->apply();

        $named = $rc->getNamedRoutes();
        $this->assertArrayHasKey('admin.login.index', $named);
    }

    public function testGroupNamePrefixCombinesAndRespectsExplicitNames(): void
    {
        $rc = new RouteCollector();

        $rc->group(function (RouteCollector $routes): void {
            $routes->group(function (RouteCollector $routes): void {
                $routes->get('/login', static fn (): Response => new Response(200));
                $routes->get('/status', static fn (): Response => new Response(200))->name('admin.auth.status.index');
            })->prefix('/auth')->namePrefix('auth')->apply();

            $routes->get('/stats', static fn (): Response => new Response(200))->name('stats.index');
        })->prefix('/admin')->namePrefix('admin')->apply();

        $named = $rc->getNamedRoutes();
        $this->assertArrayHasKey('admin.auth.login.index', $named);
        $this->assertArrayHasKey('admin.auth.status.index', $named);
        $this->assertArrayHasKey('admin.stats.index', $named);
    }

    public function testRouteNameConflictThrows(): void
    {
        $rc = new RouteCollector();

        $rc->get('/users', static fn (): Response => new Response(200));

        $this->expectException(RuntimeException::class);
        $rc->get('/users', static fn (): Response => new Response(200));
    }

    public function testResourceRoutesAndPerActionMiddleware(): void
    {
        $rc = new RouteCollector();

        $rc->resource('/users', DummyController::class)
            ->except([])
            ->middlewares([])
            ->routeMiddlewares([
                'store'   => [new HeaderAppendMiddleware('store')],
                'update'  => [new HeaderAppendMiddleware('update')],
                'destroy' => [new HeaderAppendMiddleware('destroy')],
            ])
            ->namePrefix('users')
            ->apply();

        $named = $rc->getNamedRoutes();
        $this->assertArrayHasKey('users.index', $named);
        $this->assertArrayHasKey('users.create', $named);
        $this->assertArrayHasKey('users.show', $named);
        $this->assertArrayHasKey('users.store', $named);
        $this->assertArrayHasKey('users.edit', $named);
        $this->assertArrayHasKey('users.update', $named);
        $this->assertArrayHasKey('users.destroy', $named);

        // Проверим, что у destroy есть наш middleware
        $destroy = $named['users.destroy'];
        $this->assertNotEmpty($destroy->middlewares);
    }

    public function testResourceRoutesExepts(): void
    {
        $rc = new RouteCollector();

        $rc->resource('/users', DummyController::class)
            ->except(['show'])
            ->namePrefix('users')
            ->apply();

        $named = $rc->getNamedRoutes();
        $this->assertArrayHasKey('users.index', $named);
        $this->assertArrayHasKey('users.create', $named);
        $this->assertArrayNotHasKey('users.show', $named);
        $this->assertArrayHasKey('users.store', $named);
        $this->assertArrayHasKey('users.edit', $named);
        $this->assertArrayHasKey('users.update', $named);
        $this->assertArrayHasKey('users.destroy', $named);
    }

    public function testResourceRoutesWithRestore(): void
    {
        $rc = new RouteCollector();

        $rc->resource('/users', DummyController::class)
            ->except([])
            ->middlewares([])
            ->routeMiddlewares([
                'restore' => [new HeaderAppendMiddleware('restore')],
            ])
            ->namePrefix('users')
            ->appendRestoreMethod()
            ->apply();

        $named = $rc->getNamedRoutes();
        $this->assertArrayHasKey('users.restore', $named);
        $restore = $named['users.restore'];
        $this->assertSame('POST', $restore->method);
        $this->assertSame('/users/{id}/restore', $restore->path);
        $this->assertNotEmpty($restore->middlewares);
    }

    public function testResourceRoutesWithCustomRouteParameter(): void
    {
        $rc = new RouteCollector();

        $rc->resource('/news', DummyController::class)
            ->namePrefix('news')
            ->appendRestoreMethod()
            ->routeParameter('news')
            ->apply();

        $named = $rc->getNamedRoutes();

        $this->assertSame('/news/create', $named['news.create']->path);
        $this->assertSame('/news/{news}', $named['news.show']->path);
        $this->assertSame('/news/{news}/edit', $named['news.edit']->path);
        $this->assertSame('/news/{news}', $named['news.update']->path);
        $this->assertSame('/news/{news}', $named['news.destroy']->path);
        $this->assertSame('/news/{news}/restore', $named['news.restore']->path);
    }

    public function testScopeBindingsAppliesToRoutes(): void
    {
        $rc = new RouteCollector();

        $rc->scopeBindings(function (RouteCollector $routes): void {
            $routes->get('/users/{user}/companies/{company}', static fn (): Response => new Response(200));
        });

        $routes = $rc->getRoutes();
        $this->assertCount(1, $routes);
        $this->assertTrue($routes[0]->scopeBindings);
    }

    public function testImportLoadsNestedRoutesFromRelativePath(): void
    {
        $tmpDir = sys_get_temp_dir() . '/router-import-' . uniqid('', true);
        mkdir($tmpDir . '/dispatcher', 0777, true);

        file_put_contents(
            $tmpDir . '/main.php',
            <<<'PHP'
<?php

declare(strict_types=1);

use PhpSoftBox\Router\RouteCollector;

return static function (RouteCollector $routes): void {
    $routes->group($routes->import('dispatcher/user'))->prefix('/api')->apply();
};
PHP,
        );

        file_put_contents(
            $tmpDir . '/dispatcher/user.php',
            <<<'PHP'
<?php

declare(strict_types=1);

use PhpSoftBox\Router\RouteCollector;

return static function (RouteCollector $routes): void {
    $routes->get('/users', static fn (): \PhpSoftBox\Http\Message\Response => new \PhpSoftBox\Http\Message\Response(200));
};
PHP,
        );

        $rc = new RouteCollector();

        $rc->loadFile($tmpDir . '/main.php');

        $named = $rc->getNamedRoutes();
        $this->assertArrayHasKey('api.users.index', $named);
        $this->assertSame('/api/users', $named['api.users.index']->path);

        unlink($tmpDir . '/dispatcher/user.php');
        rmdir($tmpDir . '/dispatcher');
        unlink($tmpDir . '/main.php');
        rmdir($tmpDir);
    }

    public function testRouteFileMustReturnCallable(): void
    {
        $tmpFile = sys_get_temp_dir() . '/router-invalid-' . uniqid('', true) . '.php';
        file_put_contents(
            $tmpFile,
            <<<'PHP'
<?php

declare(strict_types=1);

return ['register' => static function (): void {}];
PHP,
        );

        $rc = new RouteCollector();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Route file must return callable');

        try {
            $rc->loadFile($tmpFile);
        } finally {
            unlink($tmpFile);
        }
    }

    public function testImportGroupBuildsConfigurableGroup(): void
    {
        $tmpDir = sys_get_temp_dir() . '/router-import-group-' . uniqid('', true);
        mkdir($tmpDir . '/dispatcher', 0777, true);

        file_put_contents(
            $tmpDir . '/main.php',
            <<<'PHP'
<?php

declare(strict_types=1);

use PhpSoftBox\Router\RouteCollector;

return static function (RouteCollector $routes): void {
    $routes
        ->importGroup('dispatcher/user')
        ->prefix('/api')
        ->namePrefix('admin')
        ->apply();
};
PHP,
        );

        file_put_contents(
            $tmpDir . '/dispatcher/user.php',
            <<<'PHP'
<?php

declare(strict_types=1);

use PhpSoftBox\Router\RouteCollector;

return static function (RouteCollector $routes): void {
    $routes->get('/users', static fn (): \PhpSoftBox\Http\Message\Response => new \PhpSoftBox\Http\Message\Response(200));
};
PHP,
        );

        $rc = new RouteCollector();

        $rc->loadFile($tmpDir . '/main.php');

        $named = $rc->getNamedRoutes();
        $this->assertArrayHasKey('admin.api.users.index', $named);
        $this->assertSame('/api/users', $named['admin.api.users.index']->path);

        unlink($tmpDir . '/dispatcher/user.php');
        rmdir($tmpDir . '/dispatcher');
        unlink($tmpDir . '/main.php');
        rmdir($tmpDir);
    }
}
