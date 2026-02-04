<?php

declare(strict_types=1);

namespace PhpSoftBox\Router\Tests\Profiler;

use InvalidArgumentException;
use PhpSoftBox\Profiler\ProfileTrace;
use PhpSoftBox\Router\Profiler\RouterProfilerCollector;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\CoversMethod;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(RouterProfilerCollector::class)]
#[CoversMethod(RouterProfilerCollector::class, 'recordNotFound')]
#[CoversMethod(RouterProfilerCollector::class, 'collect')]
#[CoversMethod(RouterProfilerCollector::class, 'reset')]
final class RouterProfilerCollectorLimitTest extends TestCase
{
    /**
     * Проверим, что после предела список не растёт, счётчики продолжают считать, отдаётся truncated.
     *
     * @see RouterProfilerCollector::recordNotFound()
     * @see RouterProfilerCollector::collect()
     */
    #[Test]
    public function eventsAboveLimitAreDroppedButCounted(): void
    {
        $collector = new RouterProfilerCollector(maxItems: 2);

        for ($index = 0; $index < 5; $index++) {
            $collector->recordNotFound(1.0);
        }

        $data = $collector->collect($this->trace());

        self::assertCount(2, $data['routes']);
        self::assertSame(5, $data['not_found']);
        self::assertTrue($data['truncated']);
        self::assertSame(3, $data['dropped']);
    }

    /**
     * Проверим, что reset() очищает и список, и признак обрезки.
     *
     * @see RouterProfilerCollector::reset()
     */
    #[Test]
    public function resetClearsEventsAndTruncation(): void
    {
        $collector = new RouterProfilerCollector(maxItems: 1);

        $collector->recordNotFound(1.0);
        $collector->recordNotFound(1.0);

        $collector->reset();
        $data = $collector->collect($this->trace());

        self::assertSame([], $data['routes']);
        self::assertFalse($data['truncated']);
        self::assertSame(0, $data['dropped']);
    }

    /**
     * Проверим, что отрицательный предел отклоняется.
     *
     * @see RouterProfilerCollector::recordNotFound()
     */
    #[Test]
    public function rejectsNegativeLimit(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new RouterProfilerCollector(maxItems: -1);
    }

    private function trace(): ProfileTrace
    {
        return new ProfileTrace('id', 'test', 'test');
    }
}
