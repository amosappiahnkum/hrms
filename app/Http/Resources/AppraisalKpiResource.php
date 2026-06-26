<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AppraisalKpiResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'uuid'        => $this->uuid,
            'description' => $this->description,
            'target'      => $this->target,
            'actual'      => $this->actual,
            'order'       => $this->order,
        ];
    }
}
