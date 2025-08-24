<?php

namespace App\Http\Resources;

use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use JsonSerializable;

class QualificationResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @param  Request  $request
     * @return array|Arrayable|JsonSerializable
     */
    public function toArray($request): array|JsonSerializable|Arrayable
    {
        return [
            'id' => $this->id,
            'employee_id' => $this->employee_id,
//            'employee' => new EmployeeResource($this->employee),
            'education_level_id' => $this->education_level_id,
            'education_level' => $this->educationLevel,
            'institution' => $this->institution,
            'qualification' => $this->qualification,
            'cert_number' => $this->cert_number,
            'date' => $this->date,
//            'photo' => new PhotoResource($this->photo),
//            'certificate' => $this->photo ? '/storage/docs/qualifications/' . $this->photo->file_name : null,
            'info_update' => $this->informationUpdate,
        ];
    }
}
