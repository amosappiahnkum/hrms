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
            'type'                => ['required', 'string', 'in:appraisal,training,survey'],
            'description'         => ['nullable', 'string', 'max:2000'],
            'job_category_ids'    => ['nullable', 'array'],
            'job_category_ids.*'  => ['exists:job_categories,id'],
            'is_active'           => ['nullable', 'boolean'],
            'include_training_section' => ['nullable', 'boolean'],
            'include_next_period_targets' => ['nullable', 'boolean'],
            // Share of the overall score from the supervisor's section (the employee's section has the rest)
            'supervisor_weight'   => ['nullable', 'integer', 'between:0,100'],
        ];
    }
}
