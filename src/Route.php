<?php

declare(strict_types=1);

namespace PhpSoftBox\Router;

use Closure;
use InvalidArgumentException;

use function class_exists;
use function is_array;
use function is_callable;
use function is_string;
use function method_exists;

final readonly class Route
{
    public function __construct(
        public string $method,
        public string $path,
        public Closure|array|string $handler,
        public array $middlewares = [],
        public ?string $name = null,
        public ?string $host = null,
        public array $defaults = [],
        public array $validators = [],
    ) {
        $isInvokableClass = is_string($handler) && class_exists($handler) && method_exists($handler, '__invoke');

        if (!is_callable($handler) && !is_array($handler) && !$isInvokableClass) {
            throw new InvalidArgumentException('Handler must be callable or an array [class, method]');
        }
    }
}
