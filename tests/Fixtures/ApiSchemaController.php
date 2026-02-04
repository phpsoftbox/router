<?php

declare(strict_types=1);

namespace PhpSoftBox\Router\Tests\Fixtures;

use PhpSoftBox\Http\Message\Response;
use PhpSoftBox\Request\Request;

final class ApiSchemaController
{
    public bool $called = false;

    public function handle(ApiPayloadSchema $schema, Request $request): Response
    {
        $this->called = true;

        $data = $schema->validated();

        return new Response(200, [
            'X-Name'       => (string) ($data['name'] ?? ''),
            'X-Dependency' => $schema->dependency()->source(),
            'X-Same-Input' => $request->input('name') === ($data['name'] ?? null) ? '1' : '0',
        ]);
    }
}
