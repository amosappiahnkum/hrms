<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class DepartmentResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray($request): array
    {
        return [
            "id" => $this->uuid,
            "name" => $this->name,
            "head" => new MiniEmployeeResource($this->headOfDepartment),
            "employees" => $this->employees->count()
        ];
    }
}
