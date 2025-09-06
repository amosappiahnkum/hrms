<?php

namespace App\Http\Resources;

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
            'id' => $this->id,
            'uuid' => $this->uuid,
            'title' => $this->title,
            'first_name' => $this->first_name,
            'middle_name' => $this->middle_name,
            'last_name' => $this->last_name,
            'name' => $this->name,
            'staff_id' => $this->staff_id,
            'info_update' => $this->informationUpdate,
            'dob' => $this->dob,
            'age' => Carbon::parse($this->dob)->age,
            'gender' => $this->gender,
            'marital_status' => $this->marital_status,
            'telephone' => $this->contactDetail->telephone,
            'work_telephone' => $this->contactDetail->work_telephone,
            'work_email' => $this->contactDetail->work_email,
            'other_email' => $this->contactDetail->other_email,
            'qualification' => $this->qualification,
            'ssnit_number' => $this->ssnit_number,
            'gtec_placement' => $this->gtec_placement,
            'gtec_placement_name' => $this->gtecPlacement->name,
            'rank_id' => $this->rank_id,
            'rank' => $this->rank->name,
            'department_id' => $this->department_id,
            'department' => $this->department->name,
            'photo' => $this->photo ? '/storage/images/employees/' . $this->photo->file_name : null,
            'job' => [
                'hire_date' => $this->jobDetail->joined_date ? Carbon::parse($this->jobDetail->joined_date)->format('Y-m-d') : 'Not Updated',
                'location' => $this->jobDetail->location ?? 'Not Updated'
            ],
            'supervisor' => $this->employeeSupervisor?->supervisor->name,
            'permissions' => $this->userAccount?->getPermissionsViaRoles()->pluck('id')->merge
            ($this->userAccount?->getDirectPermissions()->pluck('id'))
        ];
    }
}
