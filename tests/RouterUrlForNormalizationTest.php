<?php

declare(strict_types=1);

namespace PhpSoftBox\Router\Tests;

use PhpSoftBox\Router\Dispatcher;
use PhpSoftBox\Router\RouteCollector;
use PhpSoftBox\Router\Router;
use PhpSoftBox\Router\RouteResolver;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\CoversMethod;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;

#[CoversClass(Router::class)]
#[CoversMethod(Router::class, 'urlFor')]
final class RouterUrlForNormalizationTest extends TestCase
{
    private function makeRouter(RouteCollector $rc): Router
    {
        return new Router(new RouteResolver($rc), new Dispatcher(), $rc);
    }

    /**
     * Проверяет нормализацию лишних слешей и удаление завершающего слеша (кроме корня).
     *
     * @see Router::urlFor()
     */
    #[Test]
    public function normalizesExtraSlashesAndTrailingSlash(): void
    {
        $rc = new RouteCollector();

        $rc->get('/base//{id}//{opt?}/', fn (ServerRequestInterface $r) => null, name: 'route');
        $router = $this->makeRouter($rc);

        $this->assertSame('/base/42', $router->urlFor('route', ['id' => 42]));
        $this->assertSame('/base/42/x', $router->urlFor('route', ['id' => 42, 'opt' => 'x']));
    }
}
