<?php

namespace App\Http\Requests;

use App\Models\Department;
use App\Models\JobCategory;
use App\Models\Position;
use App\Models\Rank;
use Carbon\Carbon;
use Illuminate\Foundation\Http\FormRequest;
use function Symfony\Component\Translation\t;

class UpdateJobDetailRequest extends FormRequest
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
            'position_uuid' => 'nullable|exists:positions,uuid',
            'position_id' => 'sometimes|exists:positions,id',
            'job_category_uuid' => 'required|exists:job_categories,uuid',
            'job_category_id' => 'sometimes|exists:job_categories,id',
            'joined_date' => 'nullable|date',
            'room' => 'nullable|string',
            'location' => 'string|in:Main Campus,Business Campus',
        ];
    }

    protected function prepareForValidation(): void
    {
        if ($this->position_uuid) {
            $d = Position::query()
                ->where('uuid', $this->position_uuid)
                ->firstOrFail();
            $this->merge([
                'position_id' => $d->id,
            ]);
        }
        if ($this->job_category_uuid) {
            $r = JobCategory::query()
                ->where('uuid', $this->job_category_uuid)
                ->firstOrFail();
            $this->merge([
                'job_category_id' => $r->id,
            ]);
        }

        if ($this->joined_date) {
            $this->merge([
                'joined_date' => Carbon::parse($this->joined_date)->format('Y-m-d'),
            ]);
        }
    }
}
