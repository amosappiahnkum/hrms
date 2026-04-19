<?php

namespace App\Http\Requests;

use App\Models\Department;
use App\Models\Rank;
use Carbon\Carbon;
use Illuminate\Foundation\Http\FormRequest;
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
            'department_uuid' => 'required|uuid|exists:departments,uuid',
            'department_id' => 'sometimes|exists:departments,id',
            'dob' => 'required|date',
            'first_name' => 'required|string',
            'gender' => 'required|in:Male,Female',
            'job_type' => 'required|in:full_time,part_time',
            'last_name' => 'required|string',
            'marital_status' => 'required|in:Single,Married,Divorced,Widow,Separated',
            'middle_name' => 'nullable|string',
            'qualification' => 'required|string',
            'ssnit_number' => ['nullable','string', Rule::unique('employees')->ignore($employee)],
            'staff_id' => ['nullable','string', Rule::unique('employees')->ignore($employee)],
            'rank_uuid' => 'required|uuid|exists:ranks,uuid',
            'rank_id' => 'sometimes|exists:ranks,id',
        ];
    }

    protected function prepareForValidation(): void
    {
        if ($this->department_uuid) {
            $d = Department::query()
                ->where('uuid', $this->department_uuid)
                ->firstOrFail();
            $this->merge([
                'department_id' => $d->id,
            ]);
        }
        if ($this->rank_uuid) {
            $r = Rank::query()
                ->where('uuid', $this->rank_uuid)
                ->firstOrFail();
            $this->merge([
                'rank_id' => $r->id,
            ]);
        }

        if ($this->dob) {
            $this->merge([
                'dob' => Carbon::parse($this->dob)->format('Y-m-d'),
            ]);
        }
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
