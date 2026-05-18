<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateQuestionOptionRequest extends FormRequest
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

            'option_text' => [
                'sometimes',
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
