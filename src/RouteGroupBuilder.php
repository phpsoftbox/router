<?php

declare(strict_types=1);

namespace PhpSoftBox\Router;

use Closure;
use Psr\Http\Server\MiddlewareInterface;

use function array_merge;
use function trim;

final class RouteGroupBuilder
{
    private string $prefix = '';

    /**
     * @var list<MiddlewareInterface|string>
     */
    private array $middlewares = [];

    private ?string $host       = null;
    private ?string $namePrefix = null;
    private bool $scopeBindings = false;

    /**
     * @var callable(array{
     *     prefix:string,
     *     middlewares:list<MiddlewareInterface|string>,
     *     host:?string,
     *     namePrefix:?string,
     *     scopeBindings:bool
     * }):void
     */
    private Closure $apply;

    public function __construct(
        Closure $apply,
    ) {
        $this->apply = $apply;
    }

    public function prefix(string $prefix): self
    {
        $this->prefix = trim($prefix);

        return $this;
    }

    public function middleware(MiddlewareInterface|string $middleware): self
    {
        return $this->middlewares([$middleware]);
    }

    /**
     * @param list<MiddlewareInterface|string> $middlewares
     */
    public function middlewares(array $middlewares): self
    {
        if ($middlewares === []) {
            return $this;
        }

        $this->middlewares = array_merge($this->middlewares, $middlewares);

        return $this;
    }

    public function host(?string $host): self
    {
        $host = $host !== null ? trim($host) : null;
        if ($host === '') {
            $host = null;
        }

        $this->host = $host;

        return $this;
    }

    public function namePrefix(?string $namePrefix): self
    {
        $namePrefix = $namePrefix !== null ? trim($namePrefix) : null;
        if ($namePrefix === '') {
            $namePrefix = null;
        }

        $this->namePrefix = $namePrefix;

        return $this;
    }

    public function scopeBindings(bool $enabled = true): self
    {
        $this->scopeBindings = $enabled;

        return $this;
    }

    public function apply(): void
    {
        ($this->apply)([
            'prefix'        => $this->prefix,
            'middlewares'   => $this->middlewares,
            'host'          => $this->host,
            'namePrefix'    => $this->namePrefix,
            'scopeBindings' => $this->scopeBindings,
        ]);
    }
}
