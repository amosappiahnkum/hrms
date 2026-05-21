<?php

namespace App\Http\Requests\Recruitment;

use App\Enums\ApplicationStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Enum;

class UpdateApplicationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'status'       => ['sometimes', new Enum(ApplicationStatus::class)],
            'cover_letter' => ['nullable', 'string'],
        ];
    }
}
