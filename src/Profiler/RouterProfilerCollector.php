<?php

declare(strict_types=1);

namespace PhpSoftBox\Router\Profiler;

use InvalidArgumentException;
use PhpSoftBox\Profiler\ProfilerCollectorInterface;
use PhpSoftBox\Profiler\ProfileTrace;
use PhpSoftBox\Router\Route;

use function count;
use function round;

final class RouterProfilerCollector implements ProfilerCollectorInterface
{
    public const int DEFAULT_MAX_ITEMS = 5000;

    private int $matches    = 0;
    private int $notFound   = 0;
    private int $dispatches = 0;

    /**
     * @var list<array<string, mixed>>
     */
    private array $routes = [];

    private int $droppedItems = 0;

    /**
     * @param int $maxItems предел числа событий в списке; после него растут только счётчики
     */
    public function __construct(
        private readonly int $maxItems = self::DEFAULT_MAX_ITEMS,
    ) {
        if ($maxItems < 0) {
            throw new InvalidArgumentException('Max items must not be negative.');
        }
    }

    public function key(): string
    {
        return 'router';
    }

    public function recordMatch(Route $route, float $durationMs): void
    {
        $this->matches++;

        $this->append([
            'event'       => 'match',
            'method'      => $route->method,
            'path'        => $route->path,
            'name'        => $route->name,
            'duration_ms' => round($durationMs, 3),
        ]);
    }

    public function recordNotFound(float $durationMs): void
    {
        $this->notFound++;

        $this->append([
            'event'       => 'not_found',
            'duration_ms' => round($durationMs, 3),
        ]);
    }

    public function recordDispatch(Route $route, float $durationMs, ?int $statusCode = null): void
    {
        $this->dispatches++;

        $this->append([
            'event'             => 'dispatch',
            'method'            => $route->method,
            'path'              => $route->path,
            'name'              => $route->name,
            'middlewares_count' => count($route->middlewares),
            'duration_ms'       => round($durationMs, 3),
            'status_code'       => $statusCode,
        ]);
    }

    public function collect(ProfileTrace $trace): array
    {
        return [
            'matches'    => $this->matches,
            'not_found'  => $this->notFound,
            'dispatches' => $this->dispatches,
            'routes'     => $this->routes,
            'truncated'  => $this->droppedItems > 0,
            'dropped'    => $this->droppedItems,
        ];
    }

    public function reset(): void
    {
        $this->matches    = 0;
        $this->notFound   = 0;
        $this->dispatches = 0;
        $this->routes     = [];

        $this->droppedItems = 0;
    }

    /**
     * @param array<string, mixed> $entry
     */
    private function append(array $entry): void
    {
        // После предела растут только счётчики: список не должен копиться в долгом процессе.
        if (count($this->routes) >= $this->maxItems) {
            $this->droppedItems++;

            return;
        }

        $this->routes[] = $entry;
    }
}
