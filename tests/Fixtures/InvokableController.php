<?php

declare(strict_types=1);

namespace PhpSoftBox\Router\Tests\Fixtures;

use PhpSoftBox\Http\Message\Response;
use Psr\Http\Message\ServerRequestInterface;

final class InvokableController
{
    public function __invoke(ServerRequestInterface $request): Response
    {
        return new Response(202, ['X-Invoked' => '1']);
    }
}
