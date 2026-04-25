<?php

namespace App\Http\Requests;

use App\Models\Employee;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class StoreAchievementRequest extends FormRequest
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
            'user_id' => 'sometimes|exists:users,id',
            'description' => 'required|string',
            'title' => 'required|string',
            'year' => 'required'
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
            'user_id' => auth()->id(),
        ]);
    }
}
