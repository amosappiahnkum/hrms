<?php

namespace App\Services;

use App\Models\DynamicForm;

class DynamicFormResolverService
{
    public function resolveExtension(string $model, string $context) {

        return DynamicForm::query()

            ->where('model', $model)

            ->where('context', $context)

            ->where('is_extension', true)

            ->where('is_active', true)

            ->with([
                'fields.options'
            ])

            ->first();
    }

    public function resolveByUuid(string $uuid) {

        return DynamicForm::query()

            ->where('uuid', $uuid)

            ->with([
                'fields.options'
            ])

            ->first();
    }
}
