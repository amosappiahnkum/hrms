<?php

namespace App\Http\Requests\Recruitment;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

class StoreCandidateSkillRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $candidateId = Auth::guard('candidate')->id();
        $skillId     = $this->route('skill')?->id;

        return [
            'name' => [
                'required',
                'string',
                'max:255',
                Rule::unique('candidate_skills', 'name')
                    ->where('candidate_id', $candidateId)
                    ->ignore($skillId),
            ],
            'level' => ['sometimes', 'nullable', 'string', Rule::in(['beginner', 'intermediate', 'advanced', 'expert'])],
        ];
    }

    public function messages(): array
    {
        return [
            'name.unique' => 'You have already added this skill.',
        ];
    }
}
