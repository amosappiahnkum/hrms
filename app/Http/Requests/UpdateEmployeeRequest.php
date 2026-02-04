<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;

class UpdateEmployeeRequest extends FormRequest
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
        $employee = $this->route('employee');

        return [
            'ssnit_number' => ['nullable','string', Rule::unique('employees')->ignore($employee)],
            'staff_id' => ['nullable','string', Rule::unique('employees')->ignore($employee)],
            'telephone' => 'nullable|string|unique:employees,telephone',
            'rank_id' => 'required|integer|exists:ranks,id',
            'department_id' => 'required|integer|exists:departments,id',
        ];
    }

    public function messages(): array
    {
        return [
            'department_id.required' => 'Department is required.',
            'telephone.unique' => 'Telephone number is already taken.',
            'staff_id.required' => 'Staff is required.',
            'rank_id.required' => 'Rank is required.',
        ];
    }
}
