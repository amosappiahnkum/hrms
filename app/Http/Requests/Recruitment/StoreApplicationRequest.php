<?php

namespace App\Http\Requests\Recruitment;

use App\Models\Recruitment\Candidate;
use App\Models\Recruitment\JobOpening;
use Illuminate\Foundation\Http\FormRequest;

class StoreApplicationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'candidate_uuid'  => ['required', 'uuid', 'exists:candidates,uuid'],
            'candidate_id'    => ['sometimes', 'exists:candidates,id'],
            'job_opening_uuid' => ['required', 'uuid', 'exists:job_openings,uuid'],
            'job_opening_id'  => ['sometimes', 'exists:job_openings,id'],
            'cover_letter'    => ['nullable', 'string'],
        ];
    }

    protected function prepareForValidation(): void
    {
        if ($this->candidate_uuid) {
            $candidate = Candidate::where('uuid', $this->candidate_uuid)->firstOrFail();
            $this->merge(['candidate_id' => $candidate->id]);
        }

        if ($this->job_opening_uuid) {
            $jobOpening = JobOpening::where('uuid', $this->job_opening_uuid)->firstOrFail();
            $this->merge(['job_opening_id' => $jobOpening->id]);
        }
    }
}
