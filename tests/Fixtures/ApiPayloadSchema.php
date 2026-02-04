<?php

declare(strict_types=1);

namespace PhpSoftBox\Router\Tests\Fixtures;

use PhpSoftBox\Request\ApiSchema;
use PhpSoftBox\Validator\Rule\PresentValidation;
use PhpSoftBox\Validator\Rule\StringValidation;
use PhpSoftBox\Validator\ValidatorInterface;

use function is_string;
use function trim;

final class ApiPayloadSchema extends ApiSchema
{
    public function __construct(
        array $payload,
        private readonly RequestSchemaDependency $dependency,
        ValidatorInterface $validator,
    ) {
        parent::__construct($payload, $validator);
    }

    public function rules(): array
    {
        return [
            'name' => [new PresentValidation(), new StringValidation()],
        ];
    }

    public function filters(): array
    {
        return [
            'name' => static fn (mixed $value): mixed => is_string($value) ? trim($value) : $value,
        ];
    }

    public function dependency(): RequestSchemaDependency
    {
        return $this->dependency;
    }
}
