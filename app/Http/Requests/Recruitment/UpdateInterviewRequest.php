<?php

namespace App\Http\Requests\Recruitment;

use App\Enums\InterviewOutcome;
use App\Enums\InterviewType;
use App\Models\SelfService\Employee;
use Carbon\Carbon;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Enum;

class UpdateInterviewRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'interviewer_uuid' => ['nullable', 'uuid', 'exists:employees,uuid'],
            'interviewer_id'   => ['nullable', 'exists:employees,id'],
            'scheduled_at'     => ['sometimes', 'date'],
            'type'             => ['nullable', new Enum(InterviewType::class)],
            'location'         => ['nullable', 'string', 'max:255'],
            'notes'            => ['nullable', 'string'],
            'outcome'          => ['nullable', new Enum(InterviewOutcome::class)],
            'feedback'         => ['nullable', 'string'],
        ];
    }

    protected function prepareForValidation(): void
    {
        if ($this->interviewer_uuid) {
            $employee = Employee::where('uuid', $this->interviewer_uuid)->firstOrFail();
            $this->merge(['interviewer_id' => $employee->id]);
        }

        if ($this->scheduled_at) {
            $this->merge(['scheduled_at' => Carbon::parse($this->scheduled_at)->format('Y-m-d H:i:s')]);
        }
    }
}
