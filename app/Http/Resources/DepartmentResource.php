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
            "uuid" => $this->uuid,
            "name" => $this->name,
            "head" => new MiniEmployeeResource($this->headOfDepartment),
            "parent_id" => $this->parent?->uuid,
            "parent_name" => $this->parent?->name,
            "employees" => $this->employees_count ?? $this->employees()->count(),
            "children_count" => $this->children_count ?? 0,
            // Its level in the structure (an index into the level names), and that name, e.g. "Section".
            "level" => $this->level,
            "level_name" => $this->levelName(),
        ];
    }
}
