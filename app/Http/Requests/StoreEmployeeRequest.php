<?php

namespace App\Http\Requests;

use App\Models\Config\Department;
use App\Models\SelfService\Rank;
use App\Services\SettingService;
use Carbon\Carbon;
use Illuminate\Foundation\Http\FormRequest;

class StoreEmployeeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $employeeForm = app(SettingService::class)->get('forms.employeeForm', []);

        $rankRequired = (bool)($employeeForm['rank']['required'] ?? false);

        return [
            'first_name' => ['required', 'string', 'max:255'],
            'last_name' => ['required', 'string', 'max:255'],
            'middle_name' => ['nullable', 'string', 'max:255'],
            'gender' => ['required'],
            'title' => ['required', 'string'],
            'job_type' => ['required', 'string'],
            'dob' => ['nullable', 'string'],
            'staff_id' => ['nullable', 'unique:employees'],
            'ssnit_number' => ['nullable', 'unique:employees'],
            'marital_status' => ['nullable'],
            'rank_uuid' => $rankRequired ? ['required', 'sometimes', 'exists:ranks,uuid'] : ['nullable', 'sometimes', 'exists:ranks,uuid'],
            'rank_id' => $rankRequired ? ['required', 'sometimes', 'exists:ranks,id'] : ['nullable', 'sometimes', 'exists:ranks,id'],
            'department_uuid' => 'required|uuid|exists:departments,uuid',
            'department_id' => 'sometimes|exists:departments,id',
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
}
