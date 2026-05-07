<?php

namespace App\Http\Resources;

use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ApprovalResource extends JsonResource
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
            'type' => $this->type,
            'model' => class_basename($this->information_type),
            'status' => $this->status,

            'reviewed_by' => $this->reviewed_by,

            'reviewed_at' => $this->reviewed_at,

            'requested_by' => $this->requestedBy?->employee?->name,
            'date_requested' => Carbon::parse($this->created_at)->diffForHumans(),
            'created_at' => Carbon::parse($this->created_at)->format('M d Y'),
        ];
    }
}
