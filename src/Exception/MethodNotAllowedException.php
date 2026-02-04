<?php

declare(strict_types=1);

namespace PhpSoftBox\Router\Exception;

use RuntimeException;

final class MethodNotAllowedException extends RuntimeException
{
    /**
     * @param string[] $allowed
     */
    public function __construct(
        private readonly array $allowed,
        string $message = 'Method Not Allowed',
    ) {
        parent::__construct($message);
    }

    /**
     * @return string[]
     */
    public function allowedMethods(): array
    {
        return $this->allowed;
    }
}
