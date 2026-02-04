<?php

declare(strict_types=1);

namespace PhpSoftBox\Router\Tests\Utils;

use Psr\Container\NotFoundExceptionInterface;
use RuntimeException;

final class ContainerNotFoundException extends RuntimeException implements NotFoundExceptionInterface
{
}
