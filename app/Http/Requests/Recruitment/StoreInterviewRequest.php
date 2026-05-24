<?php

namespace App\Http\Requests\Recruitment;

use App\Enums\InterviewType;
use App\Models\SelfService\Employee;
use Carbon\Carbon;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Enum;

class StoreInterviewRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'interviewer_uuids'   => ['nullable', 'array'],
            'interviewer_uuids.*' => ['uuid', 'exists:employees,uuid'],
            'scheduled_at'        => ['required', 'date'],
            'type'                => ['nullable', new Enum(InterviewType::class)],
            'location'            => ['nullable', 'string', 'max:255'],
            'notes'               => ['nullable', 'string'],
        ];
    }

    protected function prepareForValidation(): void
    {
        if ($this->scheduled_at) {
            $this->merge(['scheduled_at' => Carbon::parse($this->scheduled_at)->format('Y-m-d H:i:s')]);
        }
    }
}
