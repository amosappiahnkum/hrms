<?php

namespace App\Http\Resources;

use App\Helpers\Helper;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class EmployeeResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @param Request $request
     *
     * @return array
     */
    public function toArray($request): array
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
            'info_update' => $this->informationUpdate,
            'dob' => $this->dob,
            'age' => Carbon::parse($this->dob)->age,
            'gender' => $this->gender,
            'specializations' => $this->specializations,
            'research_interests' => $this->research_interests,
            'marital_status' => $this->marital_status,
            'qualification' => $this->qualification,
            'ssnit_number' => $this->ssnit_number,
            'gtec_placement' => $this->gtec_placement,
            'gtec_placement_name' => $this->gtecPlacement->name,
            'rank_uuid' => $this->rank?->uuid ?? null,
            'rank' => $this->rank?->name  ?? null,
            'department_uuid' => $this->department->uuid,
            'department' => $this->department->name,
            'photo' => Helper::getTempPhoto($this->photo),
            'job' => [
                'hire_date' => $this->jobDetail->joined_date ? Carbon::parse($this->jobDetail->joined_date)->format('Y-m-d') : 'Not Updated',
                'location' => $this->jobDetail->location ?? 'Not Updated',
                'room' => $this->jobDetail->room ?? 'Not Updated'
            ],
            // Quick-glance details for the employee list.
            'position'          => $this->jobDetail?->position?->name,
            'job_category'      => $this->jobDetail?->jobCategory?->name,
            'joined_date'       => $this->jobDetail?->joined_date ? Carbon::parse($this->jobDetail->joined_date)->toDateString() : null,
            'years_of_service'  => $this->jobDetail?->joined_date ? (int) Carbon::parse($this->jobDetail->joined_date)->diffInYears(now()) : null,
            'contract_end_date' => $this->jobDetail?->contract_end_date ? Carbon::parse($this->jobDetail->contract_end_date)->toDateString() : null,
            'on_leave'          => $this->currentLeave ? [
                'type'  => $this->currentLeave->leaveType?->name,
                'until' => Carbon::parse($this->currentLeave->end_date)->toDateString(),
            ] : null,
            'pending_update'    => $this->informationUpdate !== null,
            'supervisor'   => $this->employeeSupervisor?->supervisor->name,
            'has_account'  => $this->userAccount !== null,
            'work_email'   => $this->contactDetail?->work_email,
            'other_email'  => $this->contactDetail?->other_email,
            'telephone'    => $this->contactDetail?->telephone,
            'work_telephone' => $this->contactDetail?->work_telephone,
        ];
    }
}
