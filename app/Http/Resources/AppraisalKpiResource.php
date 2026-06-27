<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AppraisalKpiResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $target = (float) $this->target;
        $actual = $this->actual !== null ? (float) $this->actual : null;

        return [
            'uuid'             => $this->uuid,
            'description'      => $this->description,
            'target'           => $target,
            'actual'           => $actual,
            'achievement_rate' => ($target > 0 && $actual !== null)
                ? round(($actual / $target) * 100, 2)
                : null,
            'order'            => $this->order,
        ];
    }
}
