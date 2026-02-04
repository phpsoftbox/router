<?php

declare(strict_types=1);

namespace PhpSoftBox\Router\Tests\Fixtures;

use PhpSoftBox\Http\Message\Response;
use Psr\Http\Message\ServerRequestInterface;

final class RouteParamController
{
    public function show(ServerRequestInterface $request, string $id): Response
    {
        return new Response(200, ['X-Id' => $id]);
    }
}
