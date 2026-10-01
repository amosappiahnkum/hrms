<?php

namespace App\Http\Resources;

use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class LeaveRequestResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @param  Request  $request
     *
     * @return array
     */
    public function toArray($request): array
    {
        return [
            "uuid" => $this->uuid,
            "leave" => new UpcomingLeaveResource($this),
            "approvals" => $this->approvals->map(fn ($a) => [
                'role'          => $a->role,
                'decision'      => $a->decision,
                'comment'       => $a->comment,
                'days_approved' => $a->days_approved,
                'decided_at'    => $a->decided_at,
                // Names only when loaded (the detail view), so lists don't query per row.
                'approver'      => $a->relationLoaded('approver') ? $a->approver?->name : null,
            ])->values(),
            // Who the HOD step is with (the approver chosen when the leave was requested).
            "hod" => $this->whenLoaded('approver', fn () => $this->approver ? ['name' => $this->approver->name] : null),
        ];
    }
}
