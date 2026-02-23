<?php

namespace App\Http\Resources;

use App\Helpers\Helper;
use Illuminate\Http\Resources\Json\JsonResource;

class AuthResponseResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray($request): array
    {
        return [
            'id' => $this->id,
            'info' => [
                "id" => $this->employee?->uuid,
                "title" => $this->employee?->title,
                "first_name" => $this->employee?->first_name,
                "last_name" => $this->employee?->last_name,
                "other_names" => $this->employee?->other_names,
                "staff_id" => $this->employee?->staff_id,
                "gender" => $this->employee?->gender,
                "marital_status" => $this->employee?->marital_status,
                "phone_number" => $this->employee?->phone_number,
                "display_picture" => Helper::getPhotoURL($this->employee?->photo),
                "job_type" => $this->employee?->job_type,
                "job_category_id" => $this->employee?->jobDetail?->job_category_id,
                "employee_id" => $this?->employee?->uuid ?? null,
                "department_id" => $this?->employee?->department_id ?? null,
                "department" => new DepartmentResource($this?->employee?->department)
            ],
            'onboarding' => $this->employee?->onboarding == 1,
            'email' => $this->email,
            'password_changed' => $this->password_changed == 1,
            'roles' => $this->getRoleNames(),
            'permissions' => $this->getPermissionsViaRoles()->pluck('name')->merge
            ($this->getDirectPermissions()->pluck('name'))
        ];
    }
}
