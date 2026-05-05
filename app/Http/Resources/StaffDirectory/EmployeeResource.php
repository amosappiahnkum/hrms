<?php

namespace App\Http\Resources\StaffDirectory;

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
        $qual = $this?->highestQualification;

        return [
            'uuid' => $this->uuid,
            'title' => $this->title,
            'slug' => Str::slug($this->name),
            'name' => $this->name,
            'position' => $this?->latestPosition?->position?->name,
            'qualification' => $qual ? $qual->qualification . ' in ' . $qual->field : null,
            'bio' => $this->bio,
            'email' => $this?->contactDetail?->work_email,
            'department' => $this?->department?->name,
            'rank' => $this?->rank?->name,
            'specializations' => $this->specializations,
            'research_interests' => $this->research_interests,
            'photo' => Helper::getPhotoURL($this->photo),
            'room' => $this?->jobDetail?->room ?? 'Address not updated'
        ];
    }
}
