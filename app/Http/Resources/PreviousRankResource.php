<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PreviousRankResource extends JsonResource
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
            'employee_id' => $this->employee_id,
            'rank_id' => $this->rank_id,
            'name' => $this->rank->name,
            'start' => $this->start,
            'end' => $this->end,
            'user_id' => $this->user_id
        ];
    }
}
