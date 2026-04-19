<?php

namespace App\Http\Requests;

use App\Models\Employee;
use Carbon\Carbon;
use Illuminate\Foundation\Http\FormRequest;

class StoreProjectRequest extends FormRequest
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
            'title' => 'required|string|max:255',
            'start_year' => 'required|date_format:Y',
            'end_year' => 'required|date_format:Y',
            'location' => 'nullable|string|max:255',
            'role' => 'nullable|string|max:255',
            'significance' => 'required|string',
            'description' => 'required|string',
            'collaborators' => 'required|array',
            'collaborators.*' => 'string',
            'employee_uuid' => 'required|string|exists:employees,uuid',
            'employee_id' => 'sometimes|exists:employees,id',
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
            'start_year' => Carbon::parse($this->start_year)->format('Y'),
        ]);

        if ($this->end_year) {
            $this->merge([
                'end_year' => Carbon::parse($this->end_year)->format('Y'),
            ]);
        }
    }
}
