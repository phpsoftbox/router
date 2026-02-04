<?php

declare(strict_types=1);

namespace PhpSoftBox\Router\Tests\Utils;

use Psr\Container\ContainerInterface;
use ReflectionFunction;
use ReflectionMethod;

use function array_key_exists;
use function is_array;

final class ContainerCallStub implements ContainerInterface
{
    public bool $called = false;

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
}
