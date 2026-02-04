<?php

declare(strict_types=1);

namespace PhpSoftBox\Router\Attributes;

use Attribute;

use function array_filter;
use function array_values;
use function is_array;
use function is_string;
use function trim;

#[Attribute(Attribute::TARGET_PARAMETER)]
final readonly class ResolveEntity
{
    /**
     * @var list<string>
     */
    public array $with;

    /**
     * @param list<string>|string $with
     */
    public function __construct(
        public bool $withDeleted = false,
        array|string $with = [],
    ) {
        if (is_string($with)) {
            $with = [$with];
        }

        if (!is_array($with)) {
            $with = [];
        }

        $this->with = array_values(array_filter(
            $with,
            static fn (mixed $relation): bool => is_string($relation) && trim($relation) !== '',
        ));
    }
}
