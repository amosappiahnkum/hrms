<?php

namespace App\Http\Requests\Recruitment;

use Illuminate\Foundation\Http\FormRequest;

class UpdateCandidateProfileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'first_name'         => ['sometimes', 'string', 'max:255'],
            'middle_name'        => ['sometimes', 'nullable', 'string', 'max:255'],
            'last_name'          => ['sometimes', 'string', 'max:255'],
            'phone'              => ['sometimes', 'nullable', 'string', 'max:50'],
            'source'             => ['sometimes', 'nullable', 'string', 'max:255'],
            'date_of_birth'      => ['sometimes', 'nullable', 'date'],
            'gender'             => ['sometimes', 'nullable', 'string', 'max:50'],
            'nationality'        => ['sometimes', 'nullable', 'string', 'max:100'],
            'country'            => ['sometimes', 'nullable', 'string', 'max:100'],
            'region'             => ['sometimes', 'nullable', 'string', 'max:100'],
            'address'            => ['sometimes', 'nullable', 'string'],
            'summary'            => ['sometimes', 'nullable', 'string'],
            'salary_expectation' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'salary_currency'    => ['sometimes', 'nullable', 'string', 'max:10'],
            'linkedin_url'       => ['sometimes', 'nullable', 'url', 'max:500'],
            'portfolio_url'      => ['sometimes', 'nullable', 'url', 'max:500'],
        ];
    }
}
