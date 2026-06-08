<?php

namespace App\Http\Resources;

use App\Helpers\Helper;
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
            'display_picture' => Helper::getTempPhoto($this?->photo),
            'rank' => $this->rank->name,
        ];
    }
}
