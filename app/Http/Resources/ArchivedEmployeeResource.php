<?php

namespace App\Http\Resources;

use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ArchivedEmployeeResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'uuid' => $this->uuid,
            'title' => $this->title,
            'job_type' => $this->job_type,
            'first_name' => $this->first_name,
            'middle_name' => $this->middle_name,
            'last_name' => $this->last_name,
            'name' => $this->name,
            'staff_id' => $this->staff_id,
            'gender' => $this->gender,
            'telephone' => $this->contactDetail->telephone,
            'work_telephone' => $this->contactDetail->work_telephone,
            'work_email' => $this->contactDetail->work_email,
            'other_email' => $this->contactDetail->other_email,
            'rank' => $this->rank->name,
            'department' => $this->department->name,
            'photo' => $this->photo ? '/storage/images/employees/' . $this->photo->file_name : null,
            "termination_reason" => $this->terminationReason->reason,
            "termination_date" => $this->termination_date,
            "terminated_by" => $this->terminated_by,
        ];
    }
}
