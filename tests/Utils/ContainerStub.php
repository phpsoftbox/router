<?php

declare(strict_types=1);

namespace PhpSoftBox\Router\Tests\Utils;

use Psr\Container\ContainerInterface;

use function array_key_exists;

final class ContainerStub implements ContainerInterface
{
    /**
     * @param array<string, object> $entries
     */
    public function __construct(
        private array $entries = [],
    ) {
    }

    public function get(string $id): object
    {
        if (!$this->has($id)) {
            throw new ContainerNotFoundException("No entry found for {$id}.");
        }

        return $this->entries[$id];
    }

    public function has(string $id): bool
    {
        return array_key_exists($id, $this->entries);
    }
}
