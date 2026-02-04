<?php

declare(strict_types=1);

namespace PhpSoftBox\Router;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

use function array_filter;
use function array_values;
use function is_array;
use function is_callable;
use function is_dir;
use function is_file;
use function is_string;
use function rtrim;
use function sort;
use function trim;

use const SORT_STRING;

final class RouteCollectorFactory implements RouteCollectorFactoryInterface
{
    /**
     * @var string[]
     */
    private array $paths;

    public function __construct(array|string $paths)
    {
        $paths = is_array($paths) ? $paths : [$paths];

        $normalized = [];
        foreach ($paths as $path) {
            if (!is_string($path)) {
                continue;
            }
            $path = trim($path);
            if ($path === '') {
                continue;
            }
            $normalized[] = rtrim($path, '/');
        }

        $this->paths = $normalized;
    }

    public function create(): RouteCollector
    {
        $collector = new RouteCollector();

        foreach ($this->collectFiles() as $file) {
            $routes = require $file;
            if (is_callable($routes)) {
                $routes($collector);
            }
        }

        return $collector;
    }

    /**
     * @return string[]
     */
    private function collectFiles(): array
    {
        $files = [];

        foreach ($this->paths as $path) {
            if (is_file($path)) {
                $files[] = $path;
                continue;
            }

            if (!is_dir($path)) {
                continue;
            }

            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($path, RecursiveDirectoryIterator::SKIP_DOTS),
            );

            foreach ($iterator as $fileInfo) {
                if (!$fileInfo->isFile()) {
                    continue;
                }
                if ($fileInfo->getExtension() !== 'php') {
                    continue;
                }
                $files[] = $fileInfo->getPathname();
            }
        }

        if ($files === []) {
            return [];
        }

        $files = array_values(array_filter($files, static fn (string $file): bool => $file !== ''));
        sort($files, SORT_STRING);

        return $files;
    }
}
