<?php

namespace App\Http\Requests;

use App\Models\Config\Department;
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
            'name' => ['sometimes', 'required', 'string', 'max:255', Rule::unique('departments')->ignore($department)->whereNull('deleted_at')],
            'hod' => ['nullable', 'string', 'exists:employees,uuid'],
            'parent_department_id' => ['nullable', 'string', 'exists:departments,uuid'],
            // An index into the level names (org.department_levels), e.g. 2 = Section.
            'level' => ['nullable', 'integer', 'min:0', 'max:' . max(0, count(\App\Models\Config\Department::levels()) - 1)],
        ];
    }

    protected function prepareForValidation(): void
    {
        if ($this->hod === '') {
            $this->merge(['hod' => null]);
        }
    }
}
