<?php

namespace App\Http\Requests;

use App\Models\Employee;
use Carbon\Carbon;
use Illuminate\Foundation\Http\FormRequest;

class UpdateDependantRequest extends FormRequest
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
        return [
            'name' => 'required',
            'relationship' => 'required|string',
            'phone_number' => 'nullable|string',
            'alt_phone_number' => 'nullable|string',
            'dob' => 'nullable|date',
            'employee_uuid' => 'required|string|exists:employees,uuid',
            'employee_id' => 'sometimes|exists:employees,id',
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

        $this->merge([
            'dob' => Carbon::parse($this->dob)->format('Y-m-d'),
        ]);
    }
}
