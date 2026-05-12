<?php

namespace App\Services;

use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class DynamicValidationService
{
    public function validate(
        array $fields,
        array $values
    ): void {

        $rules = [];

        foreach ($fields as $field) {

            $rules[$field->name] =
                $field->rules ?? [];
        }

        $validator = Validator::make(
            $values,
            $rules
        );

        if ($validator->fails()) {

            throw new ValidationException(
                $validator
            );
        }
    }
}
