<?php

namespace App\Http\Requests;

use App\Models\Employee;
use Illuminate\Foundation\Http\FormRequest;

class UpdateExperienceRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     *
     * @return bool
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
//        $experience = $this->route('experience');

        return [
            'employee_uuid' => 'required|string|exists:employees,uuid',
            'employee_id' => 'sometimes|exists:employees,id',
            'city' => 'required|string',
            'company' => 'required|string',
            'job_title' => 'required|string',
            'from' => 'required|date',
            'to' => 'nullable|date',
            'comment' => 'required|string',
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
    }
}
