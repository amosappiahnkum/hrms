<?php

namespace App\Http\Requests;

use App\Models\Employee;
use Illuminate\Foundation\Http\FormRequest;

class UpdateNextOfKinRequest extends FormRequest
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
        return [
            'name' => 'required',
            'phone_number' => 'nullable|string',
            'email' => 'nullable|email',
            'alt_phone_number' => 'nullable|string',
            'address' => 'nullable|string',
            'employee_uuid' => 'required|string|exists:employees,uuid',
            'employee_id' => 'sometimes|exists:employees,id',
            'user_id' => 'sometimes|exists:users,id',
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
                'user_id' => auth()->id(),
            ]);
        }
    }
}
