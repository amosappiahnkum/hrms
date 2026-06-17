<?php

namespace App\Http\Resources;

use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use JsonSerializable;

class LeaveTypeResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @param  Request  $request
     * @return array|Arrayable|JsonSerializable
     */
    public function toArray($request)
    {
        return [
            'id' => $this->uuid,
            'l_id' => $this->id,
            'name' => $this->name,
            'type' => $this->request_type,
            'description' => $this->description,
            'requires_document' => (bool) $this->requires_document,
            'max_documents' => (int) $this->max_documents,
            'configs' => LeaveTypeLevelConfigResource::collection($this->leaveTypeLevelConfigs)
        ];
    }
}
