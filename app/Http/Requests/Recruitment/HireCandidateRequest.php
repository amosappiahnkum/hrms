<?php

namespace App\Http\Requests\Recruitment;

use Carbon\Carbon;
use Illuminate\Foundation\Http\FormRequest;

class HireCandidateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'title'       => ['nullable', 'string', 'max:50'],
            'middle_name' => ['nullable', 'string', 'max:255'],
            'gender'      => ['nullable', 'string'],
            'job_type'    => ['nullable', 'string'],
            'staff_id'    => ['nullable', 'string', 'unique:employees,staff_id'],
            'start_date'  => ['nullable', 'date'],
        ];
    }

    protected function prepareForValidation(): void
    {
        if ($this->start_date) {
            $this->merge(['start_date' => Carbon::parse($this->start_date)->format('Y-m-d')]);
        }
    }
}
