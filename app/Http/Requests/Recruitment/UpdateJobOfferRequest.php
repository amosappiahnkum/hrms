<?php

namespace App\Http\Requests\Recruitment;

use App\Enums\OfferStatus;
use Carbon\Carbon;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Enum;

class UpdateJobOfferRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'salary'     => ['nullable', 'numeric', 'min:0'],
            'start_date' => ['nullable', 'date'],
            'expires_at' => ['nullable', 'date'],
            'status'     => ['nullable', new Enum(OfferStatus::class)],
            'notes'      => ['nullable', 'string'],
        ];
    }

    protected function prepareForValidation(): void
    {
        if ($this->start_date) {
            $this->merge(['start_date' => Carbon::parse($this->start_date)->format('Y-m-d')]);
        }

        if ($this->expires_at) {
            $this->merge(['expires_at' => Carbon::parse($this->expires_at)->format('Y-m-d')]);
        }
    }
}
