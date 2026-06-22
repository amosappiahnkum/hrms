<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdatePositionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $position = $this->route('position');

        return [
            'name' => ['required', 'string', 'max:255', Rule::unique('positions', 'name')->ignore($position)->whereNull('deleted_at')],
        ];
    }
}
