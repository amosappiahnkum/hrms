<?php

namespace App\Http\Requests;

use App\Models\Employee;
use Carbon\Carbon;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class StoreAffiliationRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'employee_uuid' => 'required|string|exists:employees,uuid',
            'employee_id' => 'sometimes|exists:employees,id',
            'association' => 'required|string',
            'description' => 'required|string',
            'role' => 'required|string',
            'start' => 'required|date_format:Y',
            'end'   => 'nullable|date_format:Y',
        ];
    }

    protected function prepareForValidation()
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
            'start' => Carbon::parse($this->date)->format('Y'),
        ]);

        if ($this->end) {
            $this->merge([
                'end' => Carbon::parse($this->date)->format('Y'),
            ]);
        }
    }
}
