<?php

namespace App\Http\Requests;

use App\Enums\QuestionType;
use App\Models\QuestionBank\QuestionCategory;
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
        return [
            'category_uuid' => ['nullable', 'exists:question_categories,uuid'],
            'question_category_id' => 'sometimes|exists:question_categories,id',
            'type' => ['required', new Enum(QuestionType::class)],
            'text' => ['required', 'string', 'max:1000'],
            'description' => ['nullable', 'string', 'max:2000'],
            'weight' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'is_required' => ['nullable', 'boolean'],
            'is_active' => ['nullable', 'boolean'],
            'order' => ['nullable', 'integer', 'min:0'],

            // Conditional display
            'depends_on_uuid'  => ['nullable', 'exists:questions,uuid'],
            'show_when_value'    => ['nullable', 'array', 'required_with:depends_on_uuid'],
            'show_when_value.*'  => ['string', 'max:255'],

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

        if ($this->category_uuid) {
            $category = QuestionCategory::query()->where('uuid', $this->category_uuid)->firstOrFail();
            $this->merge(['question_category_id' => $category->id]);
        } elseif (!$this->question_category_id) {
            $uncategorized = QuestionCategory::firstOrCreate(
                ['name' => 'Uncategorized'],
                ['uuid' => \Illuminate\Support\Str::uuid()],
            );
            $this->merge(['question_category_id' => $uncategorized->id]);
        }

        if ($this->depends_on_uuid) {
            $parent = \App\Models\QuestionBank\Question::where('uuid', $this->depends_on_uuid)->first();
            if ($parent) {
                $this->merge(['depends_on_question_id' => $parent->id]);
            }
        }
    }
}
