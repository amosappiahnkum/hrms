<?php

namespace App\Http\Resources\public;

use App\Helpers\Helper;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Str;

class EmployeeResource extends JsonResource
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
            'slug' => Str::slug($this->name),
            'name' => $this->name,
            'email' => $this->contactDetail->work_email,
            'department' => $this->department->name,
            'rank' => $this->rank->name,
            'photo' => Helper::getPhotoURL($this->photo),
        ];
    }
}
