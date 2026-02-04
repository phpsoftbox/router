<?php

declare(strict_types=1);

namespace PhpSoftBox\Router\Tests\Fixtures;

use PhpSoftBox\Http\Message\Response;
use PhpSoftBox\Request\Request;

final class RequestSchemaController
{
    public function handle(Request $request, LoginRequestSchema $schema): Response
    {
        $data  = $schema->validated();
        $email = (string) ($data['email'] ?? '');

        return new Response(200, ['X-Email' => $email]);
    }
}
