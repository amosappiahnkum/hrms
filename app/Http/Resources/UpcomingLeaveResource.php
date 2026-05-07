<?php

namespace App\Http\Resources;

use App\Helpers\Helper;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UpcomingLeaveResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            "uuid" => $this->uuid,
            "days" => $this->days_approved,
            "days_requested" => $this->days_requested,
            "reason" => $this->reason,
            "status" => $this->status,
            "start_date" => $this->start_date,
            "end_date" => $this->end_date,
            "leave_type" => $this->leaveType->name,
            "request_type" => $this->leaveType->request_type,
            "employee" => [
                "title" => $this->employee->title,
                "staff_id" => $this->employee->staff_id,
                "uuid" => $this->employee->uuid,
                "name" => $this->employee->name,
                "department" => $this->employee->department->name,
                'photo' => Helper::getPhotoURL($this->employee->photo),
            ],
        ];
    }
}
