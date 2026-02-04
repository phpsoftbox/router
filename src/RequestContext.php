<?php

declare(strict_types=1);

namespace PhpSoftBox\Router;

use Psr\Http\Message\ServerRequestInterface;

use function ctype_digit;
use function explode;
use function preg_match;
use function str_contains;
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

    public static function fromRequest(ServerRequestInterface $request): self
    {
        $uri            = $request->getUri();
        $forwardedProto = self::firstHeaderValue($request->getHeaderLine('X-Forwarded-Proto'));
        $forwardedHost  = self::firstHeaderValue($request->getHeaderLine('X-Forwarded-Host'));
        $forwardedPort  = self::firstHeaderValue($request->getHeaderLine('X-Forwarded-Port'));

        $scheme = trim($forwardedProto);
        if ($scheme === '') {
            $scheme = trim($uri->getScheme());
        }
        $scheme = $scheme !== '' ? strtolower($scheme) : 'https';

        $host = trim($forwardedHost);
        $port = self::parsePort($forwardedPort);

        if ($host !== '' && str_contains($host, ':')) {
            if (preg_match('~^\[(.+)](?::(\d+))?$~', $host, $matches) === 1) {
                $host = '[' . $matches[1] . ']';
                if ($port === null && isset($matches[2]) && ctype_digit($matches[2])) {
                    $port = (int) $matches[2];
                }
            } elseif (preg_match('~^([^:]+):(\d+)$~', $host, $matches) === 1) {
                $host = $matches[1];
                if ($port === null && ctype_digit($matches[2])) {
                    $port = (int) $matches[2];
                }
            }
        }

        if ($host === '') {
            $host = trim($uri->getHost());
        }
        if ($port === null) {
            $port = $uri->getPort();
        }

        if ($scheme === 'http' && $port === 443) {
            $scheme = 'https';
            $port   = null;
        }

        if (($scheme === 'http' && $port === 80) || ($scheme === 'https' && $port === 443)) {
            $port = null;
        }

        return new self(
            scheme: $scheme,
            host: $host,
            port: $port,
            basePath: '',
        );
    }

    private static function firstHeaderValue(string $value): string
    {
        $trimmed = trim($value);
        if ($trimmed === '') {
            return '';
        }

        $parts = explode(',', $trimmed);

        return trim($parts[0] ?? '');
    }

    private static function parsePort(string $value): ?int
    {
        $value = trim($value);
        if ($value === '' || !ctype_digit($value)) {
            return null;
        }

        return (int) $value;
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
