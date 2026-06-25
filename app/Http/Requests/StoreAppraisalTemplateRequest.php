<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreAppraisalTemplateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name'                => ['required', 'string', 'max:255'],
            'description'         => ['nullable', 'string', 'max:2000'],
            'job_category_ids'    => ['nullable', 'array'],
            'job_category_ids.*'  => ['exists:job_categories,id'],
            'is_active'           => ['nullable', 'boolean'],
        ];
    }
}
