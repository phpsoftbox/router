<?php

declare(strict_types=1);

namespace PhpSoftBox\Router\Tests;

use InvalidArgumentException;
use PhpSoftBox\Http\Message\ServerRequest;
use PhpSoftBox\Router\Exception\InvalidRouteParameterException;
use PhpSoftBox\Router\ParamTypesEnum;
use PhpSoftBox\Router\RouteCollector;
use PhpSoftBox\Router\RouteResolver;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\CoversMethod;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function is_string;
use function preg_match;

#[CoversClass(RouteResolver::class)]
#[CoversMethod(RouteResolver::class, 'resolve')]
final class RouteResolverTest extends TestCase
{
    /**
     * Проверяем поиск обычного маршрута, ANY-маршрута и фильтрацию по host.
     *
     * @see RouteResolver::resolve()
     */
    #[Test]
    public function testResolveSimpleAndAnyAndHost(): void
    {
        $rc = new RouteCollector();

        $rc->get('/a', fn ($r) => null);
        $rc->any('/b', fn ($r) => null)->host('api.example.com');

        $resolver = new RouteResolver($rc);

        $r1 = new ServerRequest('GET', 'https://example.com/a');

        $this->assertNotNull($resolver->resolve($r1));

        $r2 = new ServerRequest('POST', 'https://api.example.com/b');

        $this->assertNotNull($resolver->resolve($r2));

        $r3 = new ServerRequest('POST', 'https://www.example.com/b');

        $this->assertNull($resolver->resolve($r3));
    }

    /**
     * Проверяем, что маршрут матчится по любому host из списка.
     *
     * @see RouteResolver::resolve()
     */
    #[Test]
    public function testResolveMatchesAnyHostFromList(): void
    {
        $rc = new RouteCollector();

        $rc->get('/admin', fn ($r) => null)->host(['dispatcher.example.com', 'dispatcher-mirror.example.com']);

        $resolver = new RouteResolver($rc);

        $this->assertNotNull($resolver->resolve(new ServerRequest('GET', 'https://dispatcher.example.com/admin')));
        $this->assertNotNull($resolver->resolve(new ServerRequest('GET', 'https://dispatcher-mirror.example.com/admin')));
        $this->assertNull($resolver->resolve(new ServerRequest('GET', 'https://www.example.com/admin')));
    }

    /**
     * Проверяем матчинг опционального сегмента при наличии и отсутствии значения.
     *
     * @see RouteResolver::resolve()
     */
    #[Test]
    public function testOptionalParamsMatchWithAndWithoutSegment(): void
    {
        $rc = new RouteCollector();

        $rc->get('/posts/{slug?}', fn ($r) => null);

        $resolver = new RouteResolver($rc);

        $r1 = new ServerRequest('GET', 'https://example.com/posts');

        $r2 = new ServerRequest('GET', 'https://example.com/posts/hello');

        $this->assertNotNull($resolver->resolve($r1));
        $this->assertNotNull($resolver->resolve($r2));
    }

    /**
     * Проверяем, что required wildcard захватывает несколько сегментов без leading slash.
     *
     * @see RouteResolver::resolve()
     */
    #[Test]
    public function testRequiredWildcardParamMatchesMultipleSegments(): void
    {
        $rc = new RouteCollector();

        $rc->get('/docs/{path*}', fn ($r) => null);

        $resolver = new RouteResolver($rc);

        $match = $resolver->resolve(new ServerRequest('GET', 'https://example.com/docs/a/b'));

        $this->assertNotNull($match);
        $this->assertSame('a/b', $match->params['path']);
        $this->assertNull($resolver->resolve(new ServerRequest('GET', 'https://example.com/docs')));
    }

    /**
     * Проверяем, что optional wildcard матчится без значения и с несколькими сегментами.
     *
     * @see RouteResolver::resolve()
     */
    #[Test]
    public function testOptionalWildcardParamMatchesWithAndWithoutSegments(): void
    {
        $rc = new RouteCollector();

        $rc->get('/docs/{path*?}', fn ($r) => null);

        $resolver = new RouteResolver($rc);

        $rootMatch = $resolver->resolve(new ServerRequest('GET', 'https://example.com/docs'));
        $pathMatch = $resolver->resolve(new ServerRequest('GET', 'https://example.com/docs/a/b'));

        $this->assertNotNull($rootMatch);
        $this->assertArrayNotHasKey('path', $rootMatch->params);
        $this->assertNotNull($pathMatch);
        $this->assertSame('a/b', $pathMatch->params['path']);
    }

    /**
     * Проверяем, что валидатор получает полное wildcard-значение.
     *
     * @see RouteResolver::resolve()
     */
    #[Test]
    public function testWildcardParamValidatorReceivesFullPath(): void
    {
        $rc = new RouteCollector();

        $rc->get('/docs/{path*}', fn ($r) => null)->validators([
            'path' => fn ($value): bool => $value === 'a/b',
        ]);

        $resolver = new RouteResolver($rc);

        $this->assertNotNull($resolver->resolve(new ServerRequest('GET', 'https://example.com/docs/a/b')));

        $this->expectException(InvalidRouteParameterException::class);
        $this->expectExceptionMessage('Invalid parameter: path');
        $resolver->resolve(new ServerRequest('GET', 'https://example.com/docs/a/c'));
    }

    /**
     * Проверяем запрет wildcard-параметра в середине маршрута.
     *
     * @see RouteResolver::resolve()
     */
    #[Test]
    public function testWildcardParamMustBeLastSegment(): void
    {
        $rc = new RouteCollector();

        $rc->get('/docs/{path*}/edit', fn ($r) => null);

        $resolver = new RouteResolver($rc);

        $this->expectException(InvalidArgumentException::class);
        $resolver->resolve(new ServerRequest('GET', 'https://example.com/docs/a/b/edit'));
    }

    /**
     * Проверяем встроенный INT-валидатор параметра маршрута.
     *
     * @see RouteResolver::resolve()
     */
    #[Test]
    public function testValidatorsIntValidAndInvalid(): void
    {
        $rc = new RouteCollector();

        $rc->get('/users/{id}', fn ($r) => null)->validators(['id' => ParamTypesEnum::INT]);
        $resolver = new RouteResolver($rc);

        $ok = new ServerRequest('GET', 'https://example.com/users/123');

        $this->assertNotNull($resolver->resolve($ok));

        $bad = new ServerRequest('GET', 'https://example.com/users/abc');

        $this->expectException(InvalidRouteParameterException::class);
        $this->expectExceptionMessage('Invalid parameter: id');
        $resolver->resolve($bad);
    }

    /**
     * Проверяем кастомный callable-валидатор параметра маршрута.
     *
     * @see RouteResolver::resolve()
     */
    #[Test]
    public function testCustomValidator(): void
    {
        $rc = new RouteCollector();

        $rc->get('/p/{slug}', fn ($r) => null)->validators([
            'slug' => fn ($v) => is_string($v) && preg_match('~^[a-z0-9-]+$~', $v) === 1,
        ]);
        $resolver = new RouteResolver($rc);

        $ok = new ServerRequest('GET', 'https://example.com/p/hello-1');

        $this->assertNotNull($resolver->resolve($ok));

        $bad = new ServerRequest('GET', 'https://example.com/p/H!');

        $this->expectException(InvalidRouteParameterException::class);
        $this->expectExceptionMessage('Invalid parameter: slug');
        $resolver->resolve($bad);
    }
}
