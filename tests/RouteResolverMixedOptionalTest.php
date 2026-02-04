<?php

declare(strict_types=1);

namespace PhpSoftBox\Router\Tests;

use PhpSoftBox\Http\Message\ServerRequest;
use PhpSoftBox\Router\RouteCollector;
use PhpSoftBox\Router\RouteResolver;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\CoversMethod;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(RouteResolver::class)]
#[CoversMethod(RouteResolver::class, 'resolve')]
final class RouteResolverMixedOptionalTest extends TestCase
{
    /**
     * Проверяет матчинг смешанных обязательных и опциональных сегментов: '/a/{x}/{y?}'.
     *
     * @see RouteResolver::resolve()
     */
    #[Test]
    public function mixedRequiredAndOptionalSegments(): void
    {
        $rc = new RouteCollector();

        $rc->get('/a/{x}/{y?}', fn ($r) => null);
        $resolver = new RouteResolver($rc);

        $ok1 = new ServerRequest('GET', 'https://example.com/a/1');

        $ok2 = new ServerRequest('GET', 'https://example.com/a/1/2');

        $bad = new ServerRequest('GET', 'https://example.com/a');

        $this->assertNotNull($resolver->resolve($ok1));
        $this->assertNotNull($resolver->resolve($ok2));
        $this->assertNull($resolver->resolve($bad));
    }
}
