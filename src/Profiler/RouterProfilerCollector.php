<?php

declare(strict_types=1);

namespace PhpSoftBox\Router\Profiler;

use PhpSoftBox\Profiler\ProfilerCollectorInterface;
use PhpSoftBox\Profiler\ProfileTrace;
use PhpSoftBox\Router\Route;

use function count;
use function round;

final class RouterProfilerCollector implements ProfilerCollectorInterface
{
    private int $matches    = 0;
    private int $notFound   = 0;
    private int $dispatches = 0;

    /**
     * @var list<array<string, mixed>>
     */
    private array $routes = [];

    public function key(): string
    {
        return 'router';
    }

    public function recordMatch(Route $route, float $durationMs): void
    {
        $this->matches++;

        $this->routes[] = [
            'event'       => 'match',
            'method'      => $route->method,
            'path'        => $route->path,
            'name'        => $route->name,
            'duration_ms' => round($durationMs, 3),
        ];
    }

    public function recordNotFound(float $durationMs): void
    {
        $this->notFound++;

        $this->routes[] = [
            'event'       => 'not_found',
            'duration_ms' => round($durationMs, 3),
        ];
    }

    public function recordDispatch(Route $route, float $durationMs, ?int $statusCode = null): void
    {
        $this->dispatches++;

        $this->routes[] = [
            'event'             => 'dispatch',
            'method'            => $route->method,
            'path'              => $route->path,
            'name'              => $route->name,
            'middlewares_count' => count($route->middlewares),
            'duration_ms'       => round($durationMs, 3),
            'status_code'       => $statusCode,
        ];
    }

    public function collect(ProfileTrace $trace): array
    {
        return [
            'matches'    => $this->matches,
            'not_found'  => $this->notFound,
            'dispatches' => $this->dispatches,
            'routes'     => $this->routes,
        ];
    }

    public function reset(): void
    {
        $this->matches    = 0;
        $this->notFound   = 0;
        $this->dispatches = 0;
        $this->routes     = [];
    }
}
