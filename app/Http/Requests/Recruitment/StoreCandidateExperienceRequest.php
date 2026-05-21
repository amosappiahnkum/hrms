<?php

namespace App\Http\Requests\Recruitment;

use Illuminate\Foundation\Http\FormRequest;

class StoreCandidateExperienceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'company_name' => ['required', 'string', 'max:255'],
            'job_title'    => ['required', 'string', 'max:255'],
            'start_date'   => ['required', 'date'],
            'end_date'     => ['nullable', 'date'],
            'is_current'   => ['nullable', 'boolean'],
            'description'  => ['nullable', 'string'],
        ];
    }
}
