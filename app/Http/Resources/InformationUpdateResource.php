<?php

namespace App\Http\Resources;

use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class InformationUpdateResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @param Request $request
     *
     * @return array
     */
    public function toArray($request): array
    {
        return [
            'id' => $this->id,
            'information' => $this->information_type,
            'department' => $this->requestedBy?->employee?->department?->name,
            'old_info' => $this->old_info,
            'new_info' => $this->new_info,
            'status' => $this->status,
            'status_changed_date' => $this->status_changed_date,
            'status_changed_by' => $this->status_changed_by,
            'requested_by' => $this->requestedBy?->employee?->name,
            'date_requested' => Carbon::parse($this->created_at)->diffForHumans(),
            'created_at' => Carbon::parse($this->created_at)->format('M d Y'),
        ];
    }
}
