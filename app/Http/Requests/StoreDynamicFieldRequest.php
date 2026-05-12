<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class StoreDynamicFieldRequest extends FormRequest
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
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [

            'dynamic_form_id' => [
                'required',
                'exists:dynamic_forms,id',
            ],

            'name' => [
                'required',
                'string',
                'max:255',
            ],

            'label' => [
                'required',
                'string',
                'max:255',
            ],

            'type' => [
                'required',
                'in:text,number,textarea,select,multi_select,date,tags,switch,checkbox,radio,email,upload',
            ],

            'col_span' => [
                'nullable',
                'integer',
                'min:1',
                'max:24',
            ],

            'sort_order' => [
                'nullable',
                'integer',
            ],

            'is_required' => [
                'nullable',
                'boolean',
            ],

            'is_active' => [
                'nullable',
                'boolean',
            ],

            'props' => [
                'nullable',
                'array',
            ],

            'rules' => [
                'nullable',
                'array',
            ],

            'behavior' => [
                'nullable',
                'array',
            ],

            'data_source' => [
                'nullable',
                'array',
            ],

            'transformers' => [
                'nullable',
                'array',
            ],

            'options' => [
                'nullable',
                'array',
            ],

            'options.*.label' => [
                'required_with:options',
                'string',
            ],

            'options.*.value' => [
                'required_with:options',
                'string',
            ],

            'options.*.sort_order' => [
                'nullable',
                'integer',
            ],
        ];
    }
}
