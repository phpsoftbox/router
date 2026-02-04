<?php

declare(strict_types=1);

namespace PhpSoftBox\Router\Tests\Utils;

use Psr\Container\ContainerInterface;
use ReflectionClass;
use ReflectionFunction;
use ReflectionMethod;
use ReflectionNamedType;
use RuntimeException;

use function array_key_exists;
use function class_exists;
use function is_array;

final class ContainerCallStub implements ContainerInterface
{
    public bool $called     = false;
    public bool $makeCalled = false;

    /**
     * @param array<string, object> $entries
     */
    public function __construct(
        private array $entries = [],
    ) {
    }

    public function get(string $id): object
    {
        if (!$this->has($id)) {
            throw new ContainerNotFoundException("No entry found for {$id}.");
        }

        return $this->entries[$id];
    }

    public function has(string $id): bool
    {
        return array_key_exists($id, $this->entries);
    }

    /**
     * @param array<string, mixed> $parameters
     */
    public function call(callable $callable, array $parameters = []): mixed
    {
        $this->called = true;

        $args       = [];
        $reflection = is_array($callable)
            ? new ReflectionMethod($callable[0], $callable[1])
            : new ReflectionFunction($callable);

        foreach ($reflection->getParameters() as $parameter) {
            $name = $parameter->getName();
            if (array_key_exists($name, $parameters)) {
                $args[$name] = $parameters[$name];
            }
        }

        return $callable(...$args);
    }

    /**
     * @param array<string, mixed> $parameters
     */
    public function make(string $id, array $parameters = []): object
    {
        $this->makeCalled = true;

        if ($this->has($id)) {
            return $this->get($id);
        }

        if (!class_exists($id)) {
            throw new ContainerNotFoundException("No entry found for {$id}.");
        }

        $reflection = new ReflectionClass($id);

        $constructor = $reflection->getConstructor();
        if ($constructor === null) {
            return new $id();
        }

        $args = [];

        foreach ($constructor->getParameters() as $parameter) {
            $name = $parameter->getName();
            if (array_key_exists($name, $parameters)) {
                $args[] = $parameters[$name];
                continue;
            }

            $type = $parameter->getType();
            if ($type instanceof ReflectionNamedType && !$type->isBuiltin() && $this->has($type->getName())) {
                $args[] = $this->get($type->getName());
                continue;
            }

            if ($parameter->isDefaultValueAvailable()) {
                $args[] = $parameter->getDefaultValue();
                continue;
            }

            if ($parameter->allowsNull()) {
                $args[] = null;
                continue;
            }

            throw new RuntimeException('Unable to resolve constructor argument: $' . $name . ' for ' . $id);
        }

        return $reflection->newInstanceArgs($args);
    }
}
