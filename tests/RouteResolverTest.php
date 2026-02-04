<?php

declare(strict_types=1);

namespace PhpSoftBox\Router\Tests;

use PhpSoftBox\Http\Message\ServerRequest;
use PhpSoftBox\Router\Exception\InvalidRouteParameterException;
use PhpSoftBox\Router\ParamTypesEnum;
use PhpSoftBox\Router\RouteCollector;
use PhpSoftBox\Router\RouteResolver;
use PHPUnit\Framework\TestCase;

use function is_string;
use function preg_match;

final class RouteResolverTest extends TestCase
{
    public function testResolveSimpleAndAnyAndHost(): void
    {
        $rc = new RouteCollector();

        $rc->get('/a', fn ($r) => null);
        $rc->any('/b', fn ($r) => null, host: 'api.example.com');

        $resolver = new RouteResolver($rc);

        $r1 = new ServerRequest('GET', 'https://example.com/a');

        $this->assertNotNull($resolver->resolve($r1));

        // ANY и проверка хоста
        $r2 = new ServerRequest('POST', 'https://api.example.com/b');

        $this->assertNotNull($resolver->resolve($r2));

        // Неверный хост — не должно матчить
        $r3 = new ServerRequest('POST', 'https://www.example.com/b');

        $this->assertNull($resolver->resolve($r3));
    }

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

    public function testValidatorsIntValidAndInvalid(): void
    {
        $rc = new RouteCollector();

        $rc->get('/users/{id}', fn ($r) => null, validators: ['id' => ParamTypesEnum::INT]);
        $resolver = new RouteResolver($rc);

        $ok = new ServerRequest('GET', 'https://example.com/users/123');

        $this->assertNotNull($resolver->resolve($ok));

        $bad = new ServerRequest('GET', 'https://example.com/users/abc');

        $this->expectException(InvalidRouteParameterException::class);
        $this->expectExceptionMessage('Invalid parameter: id');
        $resolver->resolve($bad);
    }

    public function testCustomValidator(): void
    {
        $rc = new RouteCollector();

        $rc->get('/p/{slug}', fn ($r) => null, validators: [
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
