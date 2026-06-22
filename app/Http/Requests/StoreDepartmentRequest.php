<?php

namespace App\Http\Requests;

use Auth;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;

class StoreDepartmentRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     *
     * @return bool
     */
    public function authorize(): bool
    {
        return Auth::user()->hasAnyRole(['admin', 'super-admin', 'hr']);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['nullable', 'string', Rule::unique('departments')],
            'hod' => ['nullable', 'string', 'exists:employees,uuid'],
            'parent_department_id' => ['nullable', 'string', 'exists:departments,uuid'],
        ];
    }
}
