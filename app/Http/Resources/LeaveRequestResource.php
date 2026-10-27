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
                'step'          => (int) $a->step,
                'decision'      => $a->decision,
                'comment'       => $a->comment,
                'days_approved' => $a->days_approved,
                'decided_at'    => $a->decided_at,
                // Names only when loaded (the detail view), so lists don't query per row.
                'approver'      => $a->relationLoaded('approver') ? $a->approver?->name : null,
            ])->values(),
            // The heads who approve before HR, in order, and where each stands (approved, current, waiting).
            "approval_chain" => $this->when((bool) $this->approval_chain, fn () => self::chain($this->resource)),
            // Who the HOD step is with (the approver chosen when the leave was requested).
            "hod" => $this->whenLoaded('approver', fn () => $this->approver ? ['name' => $this->approver->name] : null),
        ];
    }

    /** @return array<int, array{name: string, state: string}> */
    private static function chain(\App\Models\LeaveRequest $leave): array
    {
        $ids = array_map('intval', $leave->approval_chain ?? []);
        $people = \App\Models\SelfService\Employee::whereIn('id', $ids)->get()->keyBy('id');
        $step = (int) ($leave->approval_step ?? 0);
        $status = $leave->status?->value ?? (string) $leave->status;
        // The current head: deciding (pending), or how it ended there (rejected, cancelled); past heads approved.
        $here = $status === 'pending' ? 'current' : (in_array($status, ['hod_rejected', 'rejected', 'canceled', 'cancelled'], true) ? $status : 'approved');

        return array_map(fn ($id, $i) => [
            'name'  => trim(preg_replace('/\s+/', ' ', (string) $people->get($id)?->name)) ?: 'Someone no longer employed',
            'state' => $i < $step ? 'approved' : ($i === $step ? $here : 'waiting'),
        ], $ids, array_keys($ids));
    }
}
