<?php

declare(strict_types=1);

namespace PhpSoftBox\Router;

use Closure;
use Psr\Http\Server\MiddlewareInterface;
use RuntimeException;

use function array_merge;
use function is_string;
use function trim;

final class ResourceBuilder
{
    /**
     * @var list<string>
     */
    private array $except = [];

    /**
     * @var list<MiddlewareInterface|string>
     */
    private array $middlewares = [];

    /**
     * @var array<string, list<MiddlewareInterface|string>>
     */
    private array $routeMiddlewares = [];

    private ?string $namePrefix       = null;
    private bool $appendRestoreMethod = false;
    private array $validators         = [];
    private string $routeParameter    = 'id';

    /**
     * @param callable(array{
     *     except:list<string>,
     *     middlewares:list<MiddlewareInterface|string>,
     *     routeMiddlewares:array<string,list<MiddlewareInterface|string>>,
     *     namePrefix:?string,
     *     appendRestoreMethod:bool,
     *     validators:array<string,mixed>,
     *     routeParameter:string
     * }):void $apply
     */
    public function __construct(
        private readonly Closure $apply,
    ) {
    }

    /**
     * @param list<string> $except
     */
    public function except(array $except): self
    {
        $normalized = [];
        foreach ($except as $method) {
            if (!is_string($method)) {
                continue;
            }
            $method = trim($method);
            if ($method === '') {
                continue;
            }
            $normalized[] = $method;
        }

        $this->except = $normalized;

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

    /**
     * @param array<string, list<MiddlewareInterface|string>> $routeMiddlewares
     */
    public function routeMiddlewares(array $routeMiddlewares): self
    {
        foreach ($routeMiddlewares as $action => $middlewares) {
            $this->routeMiddleware((string) $action, $middlewares);
        }

        return $this;
    }

    /**
     * @param list<MiddlewareInterface|string> $middlewares
     */
    public function routeMiddleware(string $action, array $middlewares): self
    {
        $action = trim($action);
        if ($action === '') {
            throw new RuntimeException('Resource action name must not be empty.');
        }

        if (!isset($this->routeMiddlewares[$action])) {
            $this->routeMiddlewares[$action] = [];
        }

        $this->routeMiddlewares[$action] = array_merge($this->routeMiddlewares[$action], $middlewares);

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

    public function appendRestoreMethod(bool $enabled = true): self
    {
        $this->appendRestoreMethod = $enabled;

        return $this;
    }

    public function validators(array $validators): self
    {
        $this->validators = $validators;

        return $this;
    }

    public function where(string $name, mixed $validator): self
    {
        $name = trim($name);
        if ($name === '') {
            throw new RuntimeException('Validator parameter name must not be empty.');
        }

        $this->validators[$name] = $validator;

        return $this;
    }

    public function routeParameter(string $routeParameter): self
    {
        $routeParameter = trim($routeParameter);
        if ($routeParameter === '') {
            throw new RuntimeException('Resource route parameter must not be empty.');
        }

        $this->routeParameter = $routeParameter;

        return $this;
    }

    public function apply(): void
    {
        ($this->apply)([
            'except'              => $this->except,
            'middlewares'         => $this->middlewares,
            'routeMiddlewares'    => $this->routeMiddlewares,
            'namePrefix'          => $this->namePrefix,
            'appendRestoreMethod' => $this->appendRestoreMethod,
            'validators'          => $this->validators,
            'routeParameter'      => $this->routeParameter,
        ]);
    }
}
