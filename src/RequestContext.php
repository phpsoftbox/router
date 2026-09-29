<?php

declare(strict_types=1);

namespace PhpSoftBox\Router;

use Psr\Http\Message\ServerRequestInterface;

use function strtolower;
use function trim;

final class RequestContext
{
    public function __construct(
        private string $scheme = 'https',
        private string $host = '',
        private ?int $port = null,
        private string $basePath = '',
    ) {
    }

    /**
     * Контекст из URI запроса. Заголовки `X-Forwarded-*` не читаются: их подделывает клиент. За прокси реальные схему,
     * host и порт подставляет в URI `TrustedProxyMiddleware` (Application) — только для запросов от доверенных прокси.
     */
    public static function fromRequest(ServerRequestInterface $request): self
    {
        $uri    = $request->getUri();
        $scheme = strtolower(trim($uri->getScheme()));
        $scheme = $scheme !== '' ? $scheme : 'https';
        $port   = $uri->getPort();

        if ($scheme === 'http' && $port === 443) {
            $scheme = 'https';
            $port   = null;
        }

        if (($scheme === 'http' && $port === 80) || ($scheme === 'https' && $port === 443)) {
            $port = null;
        }

        return new self(
            scheme: $scheme,
            host: trim($uri->getHost()),
            port: $port,
            basePath: '',
        );
    }

    public function getScheme(): string
    {
        return $this->scheme;
    }

    public function setScheme(string $scheme): void
    {
        $this->scheme = trim($scheme);
    }

    public function getHost(): string
    {
        return $this->host;
    }

    public function setHost(string $host): void
    {
        $this->host = trim($host);
    }

    public function getPort(): ?int
    {
        return $this->port;
    }

    public function setPort(?int $port): void
    {
        $this->port = $port;
    }

    public function getBasePath(): string
    {
        return $this->basePath;
    }

    public function setBasePath(string $basePath): void
    {
        $this->basePath = trim($basePath);
    }
}
