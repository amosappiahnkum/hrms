<?php

namespace App\Http\Requests;

use App\Models\SelfService\Employee;
use Carbon\Carbon;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class StoreAffiliationRequest extends FormRequest
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
            'association' => 'required|string',
            'description' => 'required|string',
            'role' => 'required|string',
            'start' => 'required|date_format:Y',
            'end'   => 'nullable|date_format:Y',
        ];
    }

    protected function prepareForValidation()
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
            'user_id' => auth()->id()
        ]);

        if ($this->start) {
            $this->merge([
                'start' => self::toYear($this->start),
            ]);
        }

        if ($this->end) {
            $this->merge([
                'end' => self::toYear($this->end),
            ]);
        }
    }

    /**
     * The form sends a full date (YYYY-MM-DD); a bare year is kept as is,
     * since Carbon would read "2020" as a time of day.
     */
    private static function toYear(mixed $value): string
    {
        return preg_match('/^\d{4}$/', (string) $value)
            ? (string) $value
            : Carbon::parse($value)->format('Y');
    }
}
