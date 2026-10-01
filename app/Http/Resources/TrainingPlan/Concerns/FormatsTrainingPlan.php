<?php

namespace App\Http\Resources\TrainingPlan\Concerns;

use App\Models\User;
use BackedEnum;

trait FormatsTrainingPlan
{
    protected function option(?BackedEnum $enum): ?array
    {
        return $enum ? ['value' => $enum->value, 'label' => $enum->label()] : null;
    }

    /** Sign-off stamps: who prepared, validated, approved or rejected, and when. */
    protected function approvalTrail(): array
    {
        $stamp = fn (?User $user, $at) => $user ? ['uuid' => $user->uuid, 'name' => $user->name, 'at' => $at] : null;

        return [
            'status'            => $this->option($this->approval_status),
            'prepared'          => $stamp($this->preparer, $this->prepared_at),
            'validated'         => $stamp($this->validator, $this->validated_at),
            'approved'          => $stamp($this->approver, $this->approved_at),
            'rejected'          => $stamp($this->rejecter, $this->rejected_at),
            'rejection_comment' => $this->rejection_comment,
        ];
    }
}
