<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreLeaveRequestRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     *
     * @return bool
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'leave_type_id'   => 'required|string',
            'number_of_days'  => 'required|integer|min:1',
            'start_date'      => 'required|date',
            'reason'          => 'required|string',
            'reliever_id'     => 'nullable|string',
            'documents'       => 'nullable|array',
            'documents.*'     => 'file|mimes:pdf,jpg,jpeg,png|max:10240',
        ];
    }
}
