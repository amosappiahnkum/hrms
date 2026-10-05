<?php

namespace App\Services\Competency;

use App\Enums\Competency\AuthorizationStatus;
use App\Exceptions\UserFacingException;
use App\Models\Competency\AuthorizationActivity;
use App\Models\Competency\AuthorizationRequirement;
use App\Models\Competency\EmployeeAuthorization;
use App\Models\SelfService\Employee;
use App\Models\User;
use App\Notifications\AuthorizationNotification;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * The authorization register: recommend → grant (or decline), revoke, and keep it true by itself:
 * an authorization is suspended when the competence or a certificate it rests on lapses, and expires
 * on its valid-until date.
 */
class AuthorizationService
{
    public function __construct(
        private readonly CompetencyService $competency,
        private readonly CertificationStatusService $certificates,
    ) {
    }

    public function canGrant(User $user): bool
    {
        return $user->can('grant-authorizations');
    }

    /**
     * What the employee still lacks for the activity, in words; empty when eligible.
     *
     * @return string[]
     */
    public function unmet(Employee $employee, AuthorizationActivity $activity): array
    {
        $activity->loadMissing('requirements.competency', 'requirements.certificationType');
        $levels = $this->competency->latestAssessments([$employee->id])->get($employee->id)?->ratings->pluck('level', 'competency_id') ?? collect();

        return $activity->requirements
            ->map(function (AuthorizationRequirement $r) use ($employee, $levels) {
                if ($r->competency_id && $r->competency) {
                    $level = $levels->get($r->competency_id);

                    return $level === null || $level < $r->min_level
                        ? "{$r->competency->name}: level {$r->min_level} needed, " . ($level === null ? 'not rated' : "rated {$level}")
                        : null;
                }
                if ($r->certification_type_id && $r->certificationType) {
                    return $this->certificates->holdsValid($employee->id, $r->certification_type_id)
                        ? null
                        : "{$r->certificationType->name}: no valid certificate";
                }

                return null;
            })
            ->filter()->values()->all();
    }

    /** Recommend the employee (or, for someone who grants, authorize at once). */
    public function recommend(Employee $employee, AuthorizationActivity $activity, User $user, ?string $note, bool $grant = false, ?string $validUntil = null): EmployeeAuthorization
    {
        if (EmployeeAuthorization::current()->where('employee_id', $employee->id)->where('authorization_activity_id', $activity->id)->exists()) {
            throw new UserFacingException("{$employee->name} is already recommended for or holds this authorization.", 409);
        }
        $this->ensureEligible($employee, $activity);

        $authorization = EmployeeAuthorization::create([
            'employee_id'               => $employee->id,
            'authorization_activity_id' => $activity->id,
            'status'                    => AuthorizationStatus::RECOMMENDED,
            'recommended_by'            => $user->id,
            'recommended_at'            => now(),
            'recommendation_note'       => $note,
        ]);

        if ($grant) {
            return $this->grant($authorization, $user, $validUntil);
        }

        $this->notify($authorization, 'recommended');

        return $authorization;
    }

    /** Authorize a recommendation, or reinstate a suspended authorization. */
    public function grant(EmployeeAuthorization $authorization, User $user, ?string $validUntil = null): EmployeeAuthorization
    {
        if (!in_array($authorization->status, [AuthorizationStatus::RECOMMENDED, AuthorizationStatus::SUSPENDED], true)) {
            throw new UserFacingException('Only a recommendation or a suspended authorization can be granted.');
        }
        $this->ensureEligible($authorization->employee, $authorization->activity);

        $months = $authorization->activity->validity_months;
        $authorization->update([
            'status'             => AuthorizationStatus::AUTHORIZED,
            'authorized_by'      => $user->id,
            'authorized_at'      => now(),
            'valid_until'        => $validUntil ?? ($months ? now()->addMonthsNoOverflow($months)->toDateString() : null),
            'reason'             => null,
            'status_changed_by'  => $user->id,
            'status_changed_at'  => now(),
            'expiry_reminded_at' => null,
        ]);
        $this->notify($authorization, 'granted');

        return $authorization;
    }

    public function decline(EmployeeAuthorization $authorization, User $user, string $reason): EmployeeAuthorization
    {
        return $this->close($authorization, $user, AuthorizationStatus::DECLINED, $reason, [AuthorizationStatus::RECOMMENDED]);
    }

    public function revoke(EmployeeAuthorization $authorization, User $user, string $reason): EmployeeAuthorization
    {
        return $this->close($authorization, $user, AuthorizationStatus::REVOKED, $reason, [AuthorizationStatus::AUTHORIZED, AuthorizationStatus::SUSPENDED]);
    }

    /**
     * Suspend the employee's authorizations whose requirements they no longer meet. Run after a
     * re-rating, and daily (certificates lapse with time).
     */
    public function recheck(Employee $employee): int
    {
        $suspended = 0;
        $active = EmployeeAuthorization::where('employee_id', $employee->id)->where('status', AuthorizationStatus::AUTHORIZED)
            ->whereHas('activity')->with('activity')->get();

        foreach ($active as $authorization) {
            if ($unmet = $this->unmet($employee, $authorization->activity)) {
                $authorization->update([
                    'status'            => AuthorizationStatus::SUSPENDED,
                    'reason'            => 'Suspended automatically: ' . implode('; ', $unmet) . '.',
                    'status_changed_by' => null,
                    'status_changed_at' => now(),
                ]);
                $this->notify($authorization, 'suspended');
                $suspended++;
            }
        }

        return $suspended;
    }

    /** Daily: grants past their valid-until date expire; those expiring within 30 days are flagged once. */
    public function expire(Carbon $today): array
    {
        $expired = EmployeeAuthorization::whereIn('status', [AuthorizationStatus::AUTHORIZED, AuthorizationStatus::SUSPENDED])
            ->whereDate('valid_until', '<', $today)->get();
        foreach ($expired as $authorization) {
            $authorization->update(['status' => AuthorizationStatus::EXPIRED, 'status_changed_by' => null, 'status_changed_at' => now()]);
            $this->notify($authorization, 'expired');
        }

        $expiring = EmployeeAuthorization::where('status', AuthorizationStatus::AUTHORIZED)->whereNull('expiry_reminded_at')
            ->whereDate('valid_until', '>=', $today)->whereDate('valid_until', '<=', $today->copy()->addDays(30))->get();
        foreach ($expiring as $authorization) {
            $this->notify($authorization, 'expiring');
            $authorization->forceFill(['expiry_reminded_at' => now()])->save();
        }

        return [$expired->count(), $expiring->count()];
    }

    private function close(EmployeeAuthorization $authorization, User $user, AuthorizationStatus $to, string $reason, array $from): EmployeeAuthorization
    {
        if (!in_array($authorization->status, $from, true)) {
            throw new UserFacingException("A {$authorization->status->label()} authorization can't be {$to->label()}.");
        }

        $authorization->update(['status' => $to, 'reason' => $reason, 'status_changed_by' => $user->id, 'status_changed_at' => now()]);
        $this->notify($authorization, $to->value);

        return $authorization;
    }

    private function ensureEligible(Employee $employee, AuthorizationActivity $activity): void
    {
        if ($unmet = $this->unmet($employee, $activity)) {
            throw new UserFacingException("{$employee->name} doesn't meet the requirements yet: " . implode('; ', $unmet) . '.');
        }
    }

    /** Recommendations and expiries go to those who grant; decisions go to the employee and who recommended. */
    private function notify(EmployeeAuthorization $authorization, string $event): void
    {
        $authorization->loadMissing('employee.userAccount', 'activity', 'recommender');

        $recipients = in_array($event, ['recommended', 'expiring', 'expired', 'suspended'], true)
            ? User::permission('grant-authorizations')->get()
            : collect();
        if (!in_array($event, ['recommended', 'expiring'], true)) {
            $recipients = $recipients->push($authorization->employee?->userAccount)->push($authorization->recommender);
        }

        $recipients->filter()->unique('id')->each(fn (User $u) => $u->notify(new AuthorizationNotification($authorization, $event)));
    }
}
