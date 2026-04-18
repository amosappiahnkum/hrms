<?php

namespace App\Http\Requests;

use App\Models\Education;
use App\Models\EducationLevel;
use App\Models\Employee;
use Carbon\Carbon;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;

class UpdateQualificationRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     *
     * @return bool
     */
    public function authorize()
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, mixed>
     */
    public function rules()
    {
        $qualification = $this->route('qualification');

        return [
            'education_level_uuid' => 'required|string|exists:education_levels,uuid',
            'institution' => 'required',
            'qualification' => 'required',
            'field' => 'required',
            'country' => 'required',
            'date' => 'required|date',
            'cert_number' => ['nullable','string', Rule::unique('education')->ignore($qualification)],
            'employee_uuid' => 'required|string|exists:employees,uuid',
            'employee_id' => 'sometimes|exists:employees,id',
            'education_level_id' => 'sometimes|exists:education_levels,id',
            'type' => Rule::in(['professional', 'academic']),
        ];
    }

    protected function prepareForValidation(): void
    {
        if ($this->employee_uuid) {
            $employee = Employee::query()
                ->where('uuid', $this->employee_uuid)
                ->firstOrFail();

            $this->merge([
                'employee_id' => $employee->id,
            ]);
        }

        if ($this->education_level_uuid) {
            $e = EducationLevel::query()
                ->where('uuid', $this->education_level_uuid)
                ->firstOrFail();

            $type = $e->name == 'Professional' ? 'professional' : 'academic';
            $this->merge([
                'education_level_id' => $e->id,
                'type' => $type,
            ]);
        }

        $this->merge([
            'date' => Carbon::parse($this->date)->format('Y-m-d'),
        ]);
    }
}
