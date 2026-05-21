<?php

namespace App\Http\Requests\Recruitment;

use App\Enums\JobOpeningStatus;
use App\Models\Config\Department;
use App\Models\Position;
use Carbon\Carbon;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Enum;

class StoreJobOpeningRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'title'          => ['required', 'string', 'max:255'],
            'position_uuid'  => ['nullable', 'uuid', 'exists:positions,uuid'],
            'position_id'    => ['nullable', 'exists:positions,id'],
            'department_uuid' => ['nullable', 'uuid', 'exists:departments,uuid'],
            'department_id'  => ['nullable', 'exists:departments,id'],
            'description'    => ['nullable', 'string'],
            'requirements'   => ['nullable', 'string'],
            'salary_min'     => ['nullable', 'numeric', 'min:0'],
            'salary_max'     => ['nullable', 'numeric', 'min:0', 'gte:salary_min'],
            'location'       => ['nullable', 'string', 'max:255'],
            'status'         => ['nullable', new Enum(JobOpeningStatus::class)],
            'deadline'       => ['nullable', 'date'],
        ];
    }

    protected function prepareForValidation(): void
    {
        if ($this->position_uuid) {
            $position = Position::where('uuid', $this->position_uuid)->firstOrFail();
            $this->merge(['position_id' => $position->id]);
        }

        if ($this->department_uuid) {
            $department = Department::where('uuid', $this->department_uuid)->firstOrFail();
            $this->merge(['department_id' => $department->id]);
        }

        if ($this->deadline) {
            $this->merge(['deadline' => Carbon::parse($this->deadline)->format('Y-m-d')]);
        }
    }
}
