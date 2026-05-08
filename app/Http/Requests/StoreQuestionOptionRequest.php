<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreQuestionOptionRequest extends FormRequest
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
            'question_id' => [
                'required',
                'exists:questions,id'
            ],

            'option_text' => [
                'required',
                'string',
                'max:255'
            ],

            'option_value' => [
                'nullable',
                'string',
                'max:255'
            ],

            'order' => [
                'nullable',
                'integer',
                'min:0'
            ],
        ];
    }
}
