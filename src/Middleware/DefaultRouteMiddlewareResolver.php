<?php

declare(strict_types=1);

namespace PhpSoftBox\Router\Middleware;

use InvalidArgumentException;
use Psr\Http\Server\MiddlewareInterface;

use function get_debug_type;
use function is_string;

final class DefaultRouteMiddlewareResolver implements RouteMiddlewareResolverInterface
{
    /**
     * @param array<MiddlewareInterface|string> $middlewares
     * @return list<MiddlewareInterface>
     */
    public function resolve(array $middlewares): array
    {
        $resolved = [];

        foreach ($middlewares as $middleware) {
            if ($middleware instanceof MiddlewareInterface) {
                $resolved[] = $middleware;
                continue;
            }

            if (is_string($middleware)) {
                $instance = new $middleware();

                if (!$instance instanceof MiddlewareInterface) {
                    throw new InvalidArgumentException("Resolved middleware must implement MiddlewareInterface: {$middleware}");
                }

                $resolved[] = $instance;
                continue;
            }

            $type = get_debug_type($middleware);

            throw new InvalidArgumentException("Unsupported middleware definition: {$type}");
        }

        return $resolved;
    }
}
