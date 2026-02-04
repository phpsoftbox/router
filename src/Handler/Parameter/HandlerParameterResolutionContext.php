<?php

declare(strict_types=1);

namespace PhpSoftBox\Router\Handler\Parameter;

use PhpSoftBox\Request\Request;
use PhpSoftBox\Validator\ValidatorInterface;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ServerRequestInterface;
use RuntimeException;

use function class_exists;
use function interface_exists;

final class HandlerParameterResolutionContext
{
    private ?Request $applicationRequest = null;

    public function __construct(
        public readonly ServerRequestInterface $request,
        public readonly ContainerInterface $container,
    ) {
    }

    public function applicationRequest(): Request
    {
        if (!class_exists(Request::class) || !interface_exists(ValidatorInterface::class)) {
            throw new RuntimeException('Request parameter resolution requires phpsoftbox/request and validator.');
        }
        if (!$this->container->has(ValidatorInterface::class)) {
            throw new RuntimeException('Request parameter resolution requires ValidatorInterface in the container.');
        }

        if ($this->applicationRequest === null) {
            $validator = $this->container->get(ValidatorInterface::class);
            if (!$validator instanceof ValidatorInterface) {
                throw new RuntimeException('ValidatorInterface binding must resolve a ValidatorInterface instance.');
            }

            $this->applicationRequest = new Request($this->request, $validator);
        }

        return $this->applicationRequest;
    }

    public function validator(): ValidatorInterface
    {
        return $this->applicationRequest()->validator();
    }
}
