<?php

declare(strict_types=1);

namespace PhpSoftBox\Router\Tests\Fixtures;

use PhpSoftBox\Request\Request;
use PhpSoftBox\Request\RequestSchema;
use PhpSoftBox\Validator\Rule\PresentValidation;
use PhpSoftBox\Validator\Rule\StringValidation;

final class LoginWithDependencyRequestSchema extends RequestSchema
{
    public function __construct(
        Request $request,
        private readonly RequestSchemaDependency $dependency,
    ) {
        parent::__construct($request);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'email' => [
                new PresentValidation(),
                new StringValidation(),
            ],
        ];
    }

    public function dependencySource(): string
    {
        return $this->dependency->source();
    }
}
