<?php

declare(strict_types=1);

namespace PhpSoftBox\Router\Tests\Fixtures;

use PhpSoftBox\Http\Message\Response;
use PhpSoftBox\Request\Request;

final class RequestAwareController
{
    public function show(Request $request): Response
    {
        $name = (string) $request->input('name', '');

        return new Response(200, ['X-Name' => $name]);
    }
}
