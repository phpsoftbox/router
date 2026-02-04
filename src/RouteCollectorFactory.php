<?php

declare(strict_types=1);

namespace PhpSoftBox\Router;

use function array_filter;
use function array_values;
use function is_array;
use function is_dir;
use function is_file;
use function is_string;
use function rtrim;
use function scandir;
use function sort;
use function str_ends_with;
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
            $collector->loadFile($file);
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

            $entries = scandir($path);
            if ($entries === false) {
                continue;
            }

            foreach ($entries as $entry) {
                $entry = trim((string) $entry);
                if ($entry === '' || $entry === '.' || $entry === '..') {
                    continue;
                }

                $file = $path . '/' . $entry;
                if (!is_file($file)) {
                    continue;
                }
                if (!str_ends_with($entry, '.php')) {
                    continue;
                }
                $files[] = $file;
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
