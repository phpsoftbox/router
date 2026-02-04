<?php

declare(strict_types=1);

namespace PhpSoftBox\Router\Tests\Fixtures;

use PhpSoftBox\Http\Message\Response;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final class DemoController
{
    public function ok(): Response
    {
        return new Response(200, [], 'ok');
    }

    public function show(ServerRequestInterface $request): ResponseInterface
    {
        return new Response(200, [], 'demo')->withHeader('X-Handler', 'DemoController::show');
    }
}
