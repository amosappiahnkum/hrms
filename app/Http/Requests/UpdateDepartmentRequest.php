<?php

namespace App\Http\Requests;

use App\Models\Department;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateDepartmentRequest extends FormRequest
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
    public function rules(): array
    {
        $department = Department::where('uuid', $this->route('department'))->first();

        return [
            'name' => ['nullable','string', Rule::unique('departments')->ignore($department)],
            'hod' => 'nullable|string|exists:employees,uuid',
        ];
    }
}
