<?php

declare(strict_types=1);

namespace PhpSoftBox\Router\Tests\Utils;

use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

final class HeaderAppendMiddleware implements MiddlewareInterface
{
    public function __construct(
        private string $suffix,
        private string $header = 'X-Action',
    ) {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): \Psr\Http\Message\ResponseInterface
    {
        $resp = $handler->handle($request);
        $prev = $resp->getHeaderLine($this->header);
        $prev = $prev === '' ? 'H' : $prev;

        return $resp->withHeader($this->header, $prev . '-' . $this->suffix);
    }
}
