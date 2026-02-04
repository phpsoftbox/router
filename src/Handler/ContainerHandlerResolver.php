<?php

declare(strict_types=1);

namespace PhpSoftBox\Router\Handler;

use Closure;
use PhpSoftBox\Request\Request;
use PhpSoftBox\Request\RequestSchema;
use PhpSoftBox\Validator\ValidatorInterface;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ServerRequestInterface;
use ReflectionFunction;
use ReflectionFunctionAbstract;
use ReflectionMethod;
use ReflectionNamedType;
use RuntimeException;
use Throwable;

use function class_exists;
use function count;
use function interface_exists;
use function is_array;
use function is_callable;
use function is_object;
use function is_string;
use function is_subclass_of;
use function method_exists;

final class ContainerHandlerResolver implements HandlerResolverInterface
{
    public function __construct(
        private readonly ContainerInterface $container,
    ) {
    }

    public function resolve(callable|array|string $handler): callable
    {
        if (is_string($handler) && class_exists($handler)) {
            $instance = $this->resolveInstance($handler);
            if (method_exists($instance, '__invoke')) {
                return $this->wrapCallable([$instance, '__invoke']);
            }
        }

        if (is_array($handler) && count($handler) === 2) {
            [$class, $method] = $handler;

            if (is_object($class) && is_callable([$class, $method])) {
                return $this->wrapCallable([$class, $method]);
            }

            if (is_string($class) && class_exists($class)) {
                $controller = $this->resolveInstance($class);

                if (is_callable([$controller, $method])) {
                    return $this->wrapCallable([$controller, $method]);
                }
            }
        }

        if (is_callable($handler)) {
            return $this->wrapCallable($handler);
        }

        throw new RuntimeException('Invalid handler');
    }

    private function resolveInstance(string $class): object
    {
        try {
            return $this->container->get($class);
        } catch (Throwable) {
            return new $class();
        }
    }

    private function wrapCallable(callable $callable): callable
    {
        return function (ServerRequestInterface $request) use ($callable) {
            if (method_exists($this->container, 'call')) {
                $params = $request->getAttributes();
                unset($params['_route'], $params['_route_params']);

                $params['psrRequest'] = $request;

                $appRequest = null;
                $ref        = $this->reflectCallable($callable);

                foreach ($ref->getParameters() as $parameter) {
                    $name     = $parameter->getName();
                    $type     = $parameter->getType();
                    $typeName = $type instanceof ReflectionNamedType ? $type->getName() : null;

                    if ($typeName === ServerRequestInterface::class) {
                        $params[$name] = $request;
                        continue;
                    }

                    if ($typeName === Request::class) {
                        $appRequest ??= $this->resolveAppRequest($request);
                        if ($appRequest !== null) {
                            $params[$name] = $appRequest;
                        }
                    }

                    if (
                        $typeName !== null
                        && class_exists(RequestSchema::class)
                        && class_exists($typeName)
                        && is_subclass_of($typeName, RequestSchema::class)
                    ) {
                        $appRequest ??= $this->resolveAppRequest($request);
                        if ($appRequest === null) {
                            throw new RuntimeException('RequestSchema requires Request and Validator.');
                        }

                        $schema = new $typeName($appRequest);

                        $schema->validate();
                        $params[$name] = $schema;
                    }
                }

                if (!isset($params['request'])) {
                    $params['request'] = $request;
                }

                return $this->container->call($callable, $params);
            }

            return $callable($request);
        };
    }

    private function reflectCallable(callable $callable): ReflectionFunctionAbstract
    {
        if (is_array($callable)) {
            return new ReflectionMethod($callable[0], $callable[1]);
        }

        if (is_object($callable) && !($callable instanceof Closure)) {
            return new ReflectionMethod($callable, '__invoke');
        }

        return new ReflectionFunction($callable);
    }

    private function resolveAppRequest(ServerRequestInterface $request): ?object
    {
        if (!class_exists(Request::class) || !interface_exists(ValidatorInterface::class)) {
            return null;
        }

        if (!$this->container->has(ValidatorInterface::class)) {
            return null;
        }

        $validator = $this->container->get(ValidatorInterface::class);

        return new Request($request, $validator);
    }
}
