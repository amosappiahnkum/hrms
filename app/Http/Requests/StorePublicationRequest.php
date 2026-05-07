<?php

namespace App\Http\Requests;

use App\Models\Employee;
use Illuminate\Foundation\Http\FormRequest;

class StorePublicationRequest extends FormRequest
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
            'title' => 'required|string|max:255',
            'type' => 'string|max:255',
            'authors' => 'required|array',
            'authors.*' => 'string',
            'publication_date' => 'nullable|date',
            'publisher' => 'nullable|string|max:255',
            'edition' => 'nullable|string|max:255',
            'volume_and_issue_number' => 'nullable|string|max:255',
            'isbn_issn' => 'nullable|string|max:255',
            'doi' => 'nullable|string|max:255',
            'employee_uuid' => 'required|exists:employees,uuid',
            'employee_id' => 'sometimes|exists:employees,id',
        ];
    }


    protected function prepareForValidation(): void
    {
        if ($this->employee_uuid) {
            $employee = Employee::query()
                ->where('uuid', $this->employee_uuid)
                ->firstOrFail();

            $this->merge([
                'employee_id' => $employee->id,
            ]);
        }
    }
}
