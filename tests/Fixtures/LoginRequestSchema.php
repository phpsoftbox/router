<?php

declare(strict_types=1);

namespace PhpSoftBox\Router\Tests\Fixtures;

use PhpSoftBox\Request\RequestSchema;
use PhpSoftBox\Validator\Rule\PresentValidation;
use PhpSoftBox\Validator\Rule\StringValidation;

final class LoginRequestSchema extends RequestSchema
{
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
}
