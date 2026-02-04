<?php

declare(strict_types=1);

namespace PhpSoftBox\Router\Tests;

use PhpSoftBox\Http\Message\Response;
use PhpSoftBox\Http\Message\ServerRequest;
use PhpSoftBox\Orm\Contracts\EntityInterface;
use PhpSoftBox\Router\Exception\RouteNotFoundException;
use PhpSoftBox\Router\RequestContext;
use PhpSoftBox\Router\RouteCollector;
use PhpSoftBox\Router\UrlGenerator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\CoversMethod;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;

use function interface_exists;

#[CoversClass(UrlGenerator::class)]
#[CoversMethod(UrlGenerator::class, 'generate')]
#[CoversClass(RequestContext::class)]
final class UrlGeneratorTest extends TestCase
{
    private function makeUrlGenerator(RouteCollector $rc): UrlGenerator
    {
        return new UrlGenerator($rc);
    }

    /**
     * Проверяет нормализацию лишних слешей и удаление завершающего слеша (кроме корня).
     *
     * @see UrlGenerator::generate()
     */
    #[Test]
    public function testNormalizesExtraSlashesAndTrailingSlash(): void
    {
        $rc = new RouteCollector();

        $rc->get('/base//{id}//{opt?}/', fn (ServerRequestInterface $r) => null)->name('route');
        $urlGenerator = $this->makeUrlGenerator($rc);

        $this->assertSame('/base/42', $urlGenerator->generate('route', ['id' => 42]));
        $this->assertSame('/base/42/x', $urlGenerator->generate('route', ['id' => 42, 'opt' => 'x']));
    }

    /**
     * Проверяет подстановку обязательных и опциональных параметров в URL.
     *
     * @see UrlGenerator::generate()
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
     * Проверяет подстановку wildcard-параметров в URL.
     *
     * @see UrlGenerator::generate()
     */
    #[Test]
    public function testUrlForWildcardParam(): void
    {
        $rc = new RouteCollector();

        $rc->get('/docs/{path*}', fn (ServerRequestInterface $r) => new Response(200))->name('docs.show');
        $urlGenerator = $this->makeUrlGenerator($rc);

        $this->assertSame('/docs/a/b/c', $urlGenerator->generate('docs.show', ['path' => '/a/b/c/']));
    }

    /**
     * Проверяет удаление optional wildcard, если значение не передано.
     *
     * @see UrlGenerator::generate()
     */
    #[Test]
    public function testUrlForOptionalWildcardParamWithoutValue(): void
    {
        $rc = new RouteCollector();

        $rc->get('/docs/{path*?}', fn (ServerRequestInterface $r) => new Response(200))->name('docs.index');
        $urlGenerator = $this->makeUrlGenerator($rc);

        $this->assertSame('/docs', $urlGenerator->generate('docs.index'));
        $this->assertSame('/docs/a/b', $urlGenerator->generate('docs.index', ['path' => 'a/b']));
    }

    /**
     * Проверяет генерацию абсолютного URL по host маршрута без схемы.
     *
     * @see UrlGenerator::generate()
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
     * Проверяет генерацию абсолютного URL по primary host из списка.
     *
     * @see UrlGenerator::generate()
     */
    #[Test]
    public function testUrlForCanReturnAbsoluteUrlWithPrimaryRouteHostFromList(): void
    {
        $rc = new RouteCollector();

        $rc->get('/users/{id}', fn (ServerRequestInterface $r) => new Response(200))
            ->name('user.show')
            ->host(['admin.example.com', 'admin-mirror.example.com']);
        $urlGenerator = $this->makeUrlGenerator($rc);

        $this->assertSame('https://admin.example.com/users/42', $urlGenerator->generate('user.show', ['id' => 42], true));
    }

    /**
     * Проверяет генерацию абсолютного URL по явно выбранному host.
     *
     * @see UrlGenerator::generate()
     */
    #[Test]
    public function testUrlForCanReturnAbsoluteUrlWithExplicitHost(): void
    {
        $rc = new RouteCollector();

        $rc->get('/users/{id}', fn (ServerRequestInterface $r) => new Response(200))
            ->name('user.show')
            ->host(['admin.example.com', 'admin-mirror.example.com']);
        $urlGenerator = $this->makeUrlGenerator($rc);

        $this->assertSame(
            'https://admin-mirror.example.com/users/42',
            $urlGenerator->generate('user.show', ['id' => 42], true, 'admin-mirror.example.com'),
        );
    }

    /**
     * Проверяет генерацию абсолютного URL по host маршрута со схемой.
     *
     * @see UrlGenerator::generate()
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
     * Проверяет использование host из RequestContext, когда он разрешен маршрутом.
     *
     * @see RequestContext::__construct()
     * @see UrlGenerator::generate()
     */
    #[Test]
    public function testUrlGeneratorUsesRequestContextHostWhenItMatchesRouteHosts(): void
    {
        $rc = new RouteCollector();

        $rc->get('/users/{id}', fn (ServerRequestInterface $r) => new Response(200))
            ->name('user.show')
            ->host(['admin.example.com', 'runtime.example.com']);
        $context = new RequestContext('https', 'runtime.example.com', null);

        $urlGenerator = new UrlGenerator($rc, context: $context);

        $this->assertSame('https://runtime.example.com/users/42', $urlGenerator->generate('user.show', ['id' => 42], true));
    }

    /**
     * Проверяет fallback на первый host маршрута, когда host из RequestContext не подходит.
     *
     * @see RequestContext::__construct()
     * @see UrlGenerator::generate()
     */
    #[Test]
    public function testUrlGeneratorUsesFirstRouteHostWhenRequestContextHostDoesNotMatch(): void
    {
        $rc = new RouteCollector();

        $rc->get('/users/{id}', fn (ServerRequestInterface $r) => new Response(200))
            ->name('user.show')
            ->host(['admin.example.com', 'admin-mirror.example.com']);
        $context = new RequestContext('https', 'runtime.example.com', null);

        $urlGenerator = new UrlGenerator($rc, context: $context);

        $this->assertSame('https://admin.example.com/users/42', $urlGenerator->generate('user.show', ['id' => 42], true));
    }

    /**
     * Проверяет нормализацию абсолютного URL при runtime-схеме http и порте 443.
     *
     * @see RequestContext::__construct()
     * @see UrlGenerator::generate()
     */
    #[Test]
    public function testUrlGeneratorNormalizesHttpWithPort443ToHttps(): void
    {
        $rc = new RouteCollector();

        $rc->get('/users/{id}', fn (ServerRequestInterface $r) => new Response(200))->name('user.show');
        $context = new RequestContext('http', 'runtime.example.com', 443);

        $urlGenerator = new UrlGenerator($rc, context: $context);

        $this->assertSame('https://runtime.example.com/users/42', $urlGenerator->generate('user.show', ['id' => 42], true));
    }

    /**
     * Проверяет, что RequestContext из запроса с http:443 нормализуется в https без порта.
     *
     * @see RequestContext::fromRequest()
     * @see UrlGenerator::generate()
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
     *
     * @see UrlGenerator::generate()
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
     *
     * @see UrlGenerator::generate()
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
     *
     * @see UrlGenerator::generate()
     * @see EntityInterface::id()
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
     *
     * @see UrlGenerator::generate()
     * @see EntityInterface::id()
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
     *
     * @see UrlGenerator::generate()
     */
    #[Test]
    public function testUrlForRouteNameNotFound(): void
    {
        $urlGenerator = $this->makeUrlGenerator(new RouteCollector());

        $this->expectException(RouteNotFoundException::class);
        $urlGenerator->generate('user.show');
    }
}
