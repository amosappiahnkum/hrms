<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class GrantAndFundResource extends JsonResource
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
            'source' => $this->source,
            'purpose' => $this->purpose,
            'amount' => $this->amount,
            'benefactor' => $this->benefactor,
            'description' => $this->description,
            'start' => $this->start,
            'end' => $this->end,
            'currency' => $this->currency,
            'employee_uuid' => $this->employee->uuid,
        ];
    }
}
