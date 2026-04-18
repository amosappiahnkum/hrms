<?php

namespace App\Http\Requests;

use App\Models\Department;
use App\Models\Employee;
use App\Models\Position;
use Carbon\Carbon;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Log;

class StorePreviousPositionRequest extends FormRequest
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
        return [
            'employee_uuid' => 'required|string|exists:employees,uuid',
            'employee_id' => 'sometimes|exists:employees,id',
            'department_uuid' => 'required|string|exists:departments,uuid',
            'department_id' => 'sometimes|exists:departments,id',
            'position_uuid' => 'required|string|exists:positions,uuid',
            'position_id' => 'sometimes|exists:positions,id',
            'start' => 'required|date',
            'end' => 'nullable|date',
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

        if ($this->department_uuid) {
            $d = Department::query()
                ->where('uuid', $this->department_uuid)
                ->firstOrFail();
            $this->merge([
                'department_id' => $d->id,
            ]);
        }

        if ($this->position_uuid) {
            $p = Position::query()
                ->where('uuid', $this->position_uuid)
                ->firstOrFail();
            $this->merge([
                'position_id' => $p->id,
            ]);
        }

        $this->merge([
            'start' => Carbon::parse($this->date)->format('Y-m-d'),
        ]);

        if ($this->end) {
            $this->merge([
                'end' => Carbon::parse($this->date)->format('Y-m-d'),
            ]);
        }
    }
}
