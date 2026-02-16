<?php

namespace App\Http\Requests;

use App\Enums\QuestionType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;

class StoreQuestionRequest extends FormRequest
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
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $questionId = $this->route('question')?->id;

        return [
            'category_id' => ['nullable', 'exists:categories,id'],
            'type' => ['required', new Enum(QuestionType::class)],
            'text' => ['required', 'string', 'max:1000'],
            'description' => ['nullable', 'string', 'max:2000'],
            'weight' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'is_required' => ['nullable', 'boolean'],
            'is_active' => ['nullable', 'boolean'],
            'order' => ['nullable', 'integer', 'min:0'],

            // Options validation
            'options' => [
                Rule::requiredIf(function () {
                    $type = QuestionType::tryFrom($this->input('type'));
                    return $type && $type->requiresOptions();
                }),
                'array',
                'min:1',
            ],
            'options.*.option_text' => ['required_with:options', 'string', 'max:255'],
            'options.*.option_value' => ['nullable', 'string', 'max:255'],
            'options.*.order' => ['nullable', 'integer', 'min:0'],
        ];
    }

    public function messages(): array
    {
        return [
            'type.required' => 'Please select a question type.',
            'text.required' => 'Question text is required.',
            'options.required' => 'Multiple choice questions must have at least one option.',
            'options.*.option_text.required_with' => 'Each option must have text.',
        ];
    }

    /**
     * Prepare data for validation
     */
    protected function prepareForValidation(): void
    {
        // Auto-generate options for rating and yes/no if not provided
        if (!$this->has('options')) {
            $type = QuestionType::tryFrom($this->input('type'));

            if ($type && !$type->requiresOptions() && count($type->defaultOptions()) > 0) {
                $this->merge([
                    'options' => $type->defaultOptions(),
                ]);
            }
        }
    }
}
