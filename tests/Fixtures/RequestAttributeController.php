<?php

declare(strict_types=1);

namespace PhpSoftBox\Router\Tests\Fixtures;

use PhpSoftBox\Http\Message\Response;

final class RequestAttributeController
{
    public function handle(RequestScopedValue $version): Response
    {
        return new Response(200, ['X-Scoped-Value' => $version->value]);
    }
}
