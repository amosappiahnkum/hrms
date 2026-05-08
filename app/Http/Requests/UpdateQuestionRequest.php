<?php

namespace App\Http\Requests;

use App\Enums\QuestionType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Enum;

class UpdateQuestionRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return false;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [

            'question_category_id' => ['nullable', 'exists:question_categories,id'],

            'type' => ['sometimes', new Enum(QuestionType::class)],

            'text' => ['sometimes', 'string'],

            'description' => ['nullable', 'string'],

            'weight' => ['nullable', 'numeric', 'min:0'],

            'is_required' => ['sometimes', 'boolean'],

            'is_active' => ['sometimes', 'boolean'],

            'order' => ['nullable', 'integer', 'min:0'],
        ];
    }
}
