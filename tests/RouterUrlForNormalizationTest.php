<?php

declare(strict_types=1);

namespace PhpSoftBox\Router\Tests;

use PhpSoftBox\Router\RouteCollector;
use PhpSoftBox\Router\UrlGenerator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\CoversMethod;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;

#[CoversClass(UrlGenerator::class)]
#[CoversMethod(UrlGenerator::class, 'generate')]
final class RouterUrlForNormalizationTest extends TestCase
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
    public function normalizesExtraSlashesAndTrailingSlash(): void
    {
        $rc = new RouteCollector();

        $rc->get('/base//{id}//{opt?}/', fn (ServerRequestInterface $r) => null)->name('route');
        $urlGenerator = $this->makeUrlGenerator($rc);

        $this->assertSame('/base/42', $urlGenerator->generate('route', ['id' => 42]));
        $this->assertSame('/base/42/x', $urlGenerator->generate('route', ['id' => 42, 'opt' => 'x']));
    }
}
