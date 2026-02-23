<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class MiniEmployeeResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray($request): array
    {
        return [
            'id' => $this->uuid,
            'title' => $this->title,
            'name' => $this->name,
            'department' => $this->department?->name,
            'staff_id' => $this->staff_id,
            'rank' => $this->rank->name,
        ];
    }
}
