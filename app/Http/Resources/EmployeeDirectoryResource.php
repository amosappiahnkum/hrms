<?php

namespace App\Http\Resources;

use App\Helpers\Helper;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class EmployeeDirectoryResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->uuid,
            'title' => $this->title,
            'first_name' => $this->first_name,
            'middle_name' => $this->middle_name,
            'last_name' => $this->last_name,
            'name' => $this->name,
            'telephone' => $this->contactDetail->telephone,
            'work_telephone' => $this->contactDetail->work_telephone,
            'work_email' => $this->contactDetail->work_email,
            'other_email' => $this->contactDetail->other_email,
            'qualification' => $this->qualification,
            'rank' => $this->rank->name,
            'department' => $this->department->name,
            'photo' => Helper::getPhotoURL($this->photo),
        ];
    }
}
