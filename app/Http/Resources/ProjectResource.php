<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProjectResource extends JsonResource
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
            'end_year' => $this->end_year,
            'start_year' => $this->start_year,
            'significance' => $this->significance,
            'description' => $this->description,
            'role' => $this->role,
            'collaborators' => $this->collaborators,
            'employee_uuid' => $this->employee->uuid
        ];
    }
}
