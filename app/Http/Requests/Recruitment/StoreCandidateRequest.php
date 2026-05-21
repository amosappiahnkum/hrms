<?php

namespace App\Http\Requests\Recruitment;

use Illuminate\Foundation\Http\FormRequest;

class StoreCandidateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'first_name' => ['required', 'string', 'max:255'],
            'last_name'  => ['required', 'string', 'max:255'],
            'email'      => ['required', 'email', 'unique:candidates,email'],
            'phone'      => ['nullable', 'string', 'max:50'],
            'source'     => ['nullable', 'string', 'max:255'],
            'cv_path'    => ['nullable', 'string'],
        ];
    }
}
