<?php

namespace App\Http\Resources;

use App\Helpers\Helper;
use App\Models\LeaveRequestDocument;
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
                'photo' => Helper::getTempPhoto($this->employee->photo),
            ],
            "reliever" => $this->reliever ? [
                "uuid" => $this->reliever->uuid,
                "name" => $this->reliever->name,
                "department" => $this->reliever->department->name,
                'photo' => Helper::getTempPhoto($this->reliever->photo),
            ] : null,
            "resumption" => $this->resumption ? [
                'uuid'                  => $this->resumption->uuid,
                'status'                => $this->resumption->status,
                'employee_confirmed_at' => $this->resumption->employee_confirmed_at,
                'hod_acknowledged_at'   => $this->resumption->hod_acknowledged_at,
            ] : null,
            "requires_document" => (bool) $this->leaveType->requires_document,
            "max_documents" => (int) $this->leaveType->max_documents,
            "documents" => $this->documents->map(fn(LeaveRequestDocument $doc) => [
                'id'        => $doc->id,
                'file_name' => $doc->file_name,
                'mime_type' => $doc->mime_type,
                'url'       => Helper::getTempUrl($doc->file_path),
            ]),
        ];
    }
}
