<?php

declare(strict_types=1);

namespace PhpSoftBox\Router\Handler;

use Closure;
use RuntimeException;

use function class_exists;
use function count;
use function is_array;
use function is_callable;
use function is_object;
use function is_string;
use function method_exists;

final class DefaultHandlerResolver implements HandlerResolverInterface
{
    public function resolve(callable|array|string $handler): callable
    {
        if (is_string($handler) && class_exists($handler)) {
            $instance = new $handler();

            if (method_exists($instance, '__invoke')) {
                return [$instance, '__invoke'];
            }
        }

        if (is_array($handler) && count($handler) === 2) {
            [$class, $method] = $handler;

            if (is_object($class) && is_callable([$class, $method])) {
                return [$class, $method];
            }

            if (is_string($class) && class_exists($class)) {
                $controller = new $class();

                if (is_callable([$controller, $method])) {
                    return [$controller, $method];
                }
            }
        }

        if ($handler instanceof Closure) {
            return $handler;
        }

        if (is_callable($handler)) {
            return $handler;
        }

        throw new RuntimeException('Invalid handler');
    }
}
