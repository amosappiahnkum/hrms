<?php

namespace App\Services\TrainingPlan;

use App\Enums\TrainingPlan\ApprovalStatus;
use App\Models\Config\Department;
use App\Models\SelfService\Employee;
use App\Models\TrainingPlan\TrainingPlan;
use App\Models\User;
use App\Services\Competency\CompetencyService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

/**
 * Who may do what with training plans.
 *
 *  - Permission holders (view / prepare / review) see whole plans. Preparers (HR) change them.
 *  - Heads of department see and, while a plan's collection window is open, add the trainings of
 *    the people in the departments they head (sub-departments included). No permission needed.
 */
class TrainingPlanAccess
{
    public function __construct(private readonly CompetencyService $departments) {}

    public function canPrepare(User $user): bool
    {
        return $user->can('prepare-training-plan');
    }

    public function seesEverything(User $user): bool
    {
        return $user->canAny(['view-training-plan', 'prepare-training-plan', 'review-training-plan']);
    }

    public function headsDepartments(User $user): bool
    {
        return $this->teamDepartmentIds($user)->isNotEmpty();
    }

    public function hasAccess(User $user): bool
    {
        return $this->seesEverything($user) || $this->headsDepartments($user);
    }

    /** The departments the user heads, with their sub-departments. */
    public function teamDepartmentIds(User $user): Collection
    {
        if (!$user->employee_id) {
            return collect();
        }

        return Department::where('hod', $user->employee_id)->get()
            ->flatMap(fn (Department $d) => $this->departments->withSubDepartments($d))
            ->unique()->values();
    }

    /** Employees in the user's departments. */
    public function team(User $user): Builder
    {
        return Employee::query()->whereIn('department_id', $this->teamDepartmentIds($user));
    }

    /** Limit plan items to what the user sees: everything, or their departments' trainees. */
    public function scopeItems($items, User $user)
    {
        return $this->seesEverything($user)
            ? $items
            : $items->whereHas('employee', fn ($e) => $e->whereIn('department_id', $this->teamDepartmentIds($user)));
    }

    /** Whether every one of these employees is in the user's departments. */
    public function leadsAll(User $user, Collection $employeeIds): bool
    {
        $employeeIds = $employeeIds->unique();

        return $employeeIds->isNotEmpty()
            && $this->team($user)->whereIn('id', $employeeIds)->count() === $employeeIds->count();
    }

    /**
     * Give review-training-plan to exactly the people who sign plans: those in the configured
     * levels, and those in the levels a plan awaiting sign-off was submitted with.
     */
    public function syncReviewers(): void
    {
        $configured = DB::table('training_plan_approval_level_user')
            ->join('training_plan_approval_levels as l', 'l.id', '=', 'training_plan_approval_level_user.training_plan_approval_level_id')
            ->whereNull('l.deleted_at')
            ->pluck('user_id');
        $inFlight = TrainingPlan::whereIn('approval_status', [ApprovalStatus::PENDING_VALIDATION->value, ApprovalStatus::PENDING_APPROVAL->value])
            ->pluck('approval_chain')
            ->flatMap(fn ($chain) => collect($chain)->pluck('user_ids')->flatten());
        $reviewers = $configured->merge($inFlight)->map(fn ($id) => (int) $id)->unique();

        $holders = User::permission('review-training-plan')->get();
        $holders->reject(fn (User $u) => $reviewers->contains($u->id))->each->revokePermissionTo('review-training-plan');
        User::whereIn('id', $reviewers->diff($holders->pluck('id')))->get()->each->givePermissionTo('review-training-plan');

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
