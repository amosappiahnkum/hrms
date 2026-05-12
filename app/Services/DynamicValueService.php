<?php

namespace App\Services;

use App\Models\DynamicField;
use Illuminate\Support\Str;

class DynamicValueService
{
    public function save(
        mixed $model,
        array $values
    ): void {

        $fields = DynamicField::query()

            ->whereIn(
                'name',
                array_keys($values)
            )

            ->get()

            ->keyBy('name');

        foreach ($values as $key => $value) {

            $field = $fields[$key] ?? null;

            if (!$field) {
                continue;
            }

            $model->dynamicValues()
                ->updateOrCreate(['dynamic_field_id' => $field->id],

                    [
                        'uuid' => Str::uuid(),

                        'value' => $value,
                    ]
                );
        }
    }

    public function resolve(mixed $model) {

        return $model->dynamicValues?->mapWithKeys(function ($item) {
                return [
                    $item->field->name => $item->value
                ];
            })->toArray();
    }
}
