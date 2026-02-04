<?php

declare(strict_types=1);

namespace PhpSoftBox\Router\Tests\Utils;

use PhpSoftBox\Http\Message\Response;
use Psr\Http\Message\ServerRequestInterface;

final class DummyController
{
    public function hello(ServerRequestInterface $r): Response
    {
        return new Response(201);
    }

    public function index(ServerRequestInterface $r): Response
    {
        return new Response(200);
    }
    public function show(ServerRequestInterface $r): Response
    {
        return new Response(200);
    }
    public function store(ServerRequestInterface $r): Response
    {
        return new Response(200);
    }
    public function update(ServerRequestInterface $r): Response
    {
        return new Response(200);
    }
    public function destroy(ServerRequestInterface $r): Response
    {
        return new Response(200);
    }
}
