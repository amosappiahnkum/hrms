<?php

namespace App\Http\Requests\Recruitment;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateCandidateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'first_name' => ['sometimes', 'string', 'max:255'],
            'last_name'  => ['sometimes', 'string', 'max:255'],
            'email'      => ['sometimes', 'email', Rule::unique('candidates', 'email')->ignore($this->route('candidate')->id)],
            'phone'      => ['nullable', 'string', 'max:50'],
            'source'     => ['nullable', 'string', 'max:255'],
            'cv_path'    => ['nullable', 'string'],
        ];
    }
}
