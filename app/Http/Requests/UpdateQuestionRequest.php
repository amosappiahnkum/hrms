<?php

namespace App\Http\Requests;

use App\Enums\QuestionType;
use App\Models\QuestionBank\Question;
use App\Models\QuestionBank\QuestionCategory;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Enum;

class UpdateQuestionRequest extends FormRequest
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
            'category_uuid'   => ['nullable', 'exists:question_categories,uuid'],
            'question_category_id' => ['nullable', 'exists:question_categories,id'],
            'type' => ['sometimes', new Enum(QuestionType::class)],
            'text' => ['sometimes', 'string'],
            'description' => ['nullable', 'string'],
            'weight' => ['nullable', 'numeric', 'min:0'],
            'is_required' => ['sometimes', 'boolean'],
            'is_active' => ['sometimes', 'boolean'],
            'order' => ['nullable', 'integer', 'min:0'],
            'depends_on_uuid'  => ['nullable', 'exists:questions,uuid'],
            'show_when_value'    => ['nullable', 'array'],
            'show_when_value.*'  => ['string', 'max:255'],
        ];
    }

    protected function prepareForValidation(): void
    {
        if ($this->category_uuid) {
            $category = QuestionCategory::where('uuid', $this->category_uuid)->firstOrFail();
            $this->merge(['question_category_id' => $category->id]);
        }

        if ($this->filled('depends_on_uuid')) {
            $parent = Question::where('uuid', $this->depends_on_uuid)->first();
            if ($parent) {
                $this->merge(['depends_on_question_id' => $parent->id]);
            }
        } elseif ($this->has('depends_on_uuid')) {
            // Explicitly set to null — clear the dependency
            $this->merge(['depends_on_question_id' => null, 'show_when_value' => null]);
        }
    }
}
