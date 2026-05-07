<?php

namespace App\Http\Requests;

use App\Models\Employee;
use Carbon\Carbon;
use Illuminate\Foundation\Http\FormRequest;

class UpdateGrantAndFundRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     *
     * @return bool
     */
    public function authorize()
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, mixed>
     */
    public function rules()
    {
        return [
            'source' => 'required|string|in:Academic Institution,Foundation / Trust,Corporate Sponsorship,Multilateral Agency,Bilateral Agency,Internal Funding,Philanthropy / Donation',
            'purpose' => 'nullable|string|in:Scholarship / Bursary,Training / Capacity Building,Infrastructure Development,Equipment Purchase,Travel / Conference,Innovation / Startup,Consultancy',
            'amount' => 'nullable|numeric|min:0',
            'benefactor' => 'nullable|string',
            'currency' => 'required|string',
            'start' => 'required|date_format:Y',
            'end' => 'nullable|date_format:Y',
            'description' => 'nullable|string',
            'employee_uuid' => 'required|string|exists:employees,uuid',
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

        $this->merge([
            'start' => Carbon::parse($this->start)->format('Y'),
        ]);

        if ($this->end) {
            $this->merge([
                'end' => Carbon::parse($this->end)->format('Y'),
            ]);
        }
    }
}
