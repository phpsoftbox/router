<?php

declare(strict_types=1);

namespace PhpSoftBox\Router\Profiler;

use PhpSoftBox\Profiler\ProfilerExtensionInterface;
use PhpSoftBox\Profiler\ProfilerRegistryInterface;

final class RouterProfilerExtension implements ProfilerExtensionInterface
{
    private RouterProfilerCollector $collector;

    public function __construct(?RouterProfilerCollector $collector = null)
    {
        $this->collector = $collector ?? new RouterProfilerCollector();
    }

    public function collector(): RouterProfilerCollector
    {
        return $this->collector;
    }

    public function register(ProfilerRegistryInterface $registry): void
    {
        $registry->addCollector($this->collector);
    }
}
