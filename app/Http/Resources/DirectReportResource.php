<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class DirectReportResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @param  Request  $request
     *
     * @return array
     */
    public function toArray($request): array
    {
        return [
            "id" => $this->id,
            "supervisor_id" => $this->supervisor_id,
            "supervisor" => $this->supervisor->name,
            "employee_id" => $this->employee_id,
            "employee" => [
                "name" => $this->employee->name,
                "email" => $this->employee->contactDetail->work_email
            ],
            "method" => "Direct"
        ];
    }
}
