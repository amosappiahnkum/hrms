<?php

namespace App\Http\Requests\Recruitment;

use Illuminate\Foundation\Http\FormRequest;

class RegisterCandidateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'first_name'  => ['required', 'string', 'max:255'],
            'middle_name' => ['nullable', 'string', 'max:255'],
            'last_name'   => ['required', 'string', 'max:255'],
            'email'      => ['required', 'email', 'unique:candidates,email'],
            'password'   => ['required', 'string', 'confirmed', 'min:8'],
            'phone'      => ['nullable', 'string', 'max:50'],
            'source'     => ['nullable', 'string', 'max:255'],
        ];
    }
}
