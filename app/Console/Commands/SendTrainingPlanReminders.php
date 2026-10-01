<?php

namespace App\Console\Commands;

use App\Enums\TrainingPlan\ApprovalStatus;
use App\Enums\TrainingPlan\TrainingStatus;
use App\Models\TrainingPlan\TrainingPlanItem;
use App\Models\User;
use App\Notifications\TrainingPlanReminderNotification;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

/**
 * Reminders for approved, dated trainings that are still expected to happen:
 * - 30, 14, 7 and 1 day(s) before the start date → the trainee, their HOD and HR;
 * - the day after the training ends while it is still Not Started/Scheduled → HR, to update its status.
 * "HR" is everyone who can prepare training plans.
 */
class SendTrainingPlanReminders extends Command
{
    protected $signature = 'training-plan:send-reminders';

    protected $description = 'Send reminders for upcoming planned trainings and follow-ups for past ones';

    private const DAYS_BEFORE = [30, 14, 7, 1];

    public function handle(): void
    {
        $today = Carbon::today();
        $hrUsers = User::permission('prepare-training-plan')->get();
        $sent = 0;

        $upcomingDates = collect(self::DAYS_BEFORE)->map(fn ($d) => $today->copy()->addDays($d)->toDateString());

        foreach ($this->openItems()->whereIn('planned_start_date', $upcomingDates)->get() as $item) {
            $days = (int) $today->diffInDays($item->planned_start_date, false);

            foreach ($this->recipients($item, $hrUsers) as [$user, $type]) {
                $user->notify(new TrainingPlanReminderNotification($item, $days, $type));
                $sent++;
            }
        }

        // Follow-up the day after the training ended (or started, when no end date is set).
        $yesterday = $today->copy()->subDay()->toDateString();
        $overdue = $this->openItems()->where(function ($q) use ($yesterday) {
            $q->whereDate('planned_end_date', $yesterday)
                ->orWhere(fn ($q) => $q->whereNull('planned_end_date')->whereDate('planned_start_date', $yesterday));
        })->get();

        foreach ($overdue as $item) {
            $days = (int) $today->diffInDays($item->planned_start_date, false);

            foreach ($hrUsers as $user) {
                $user->notify(new TrainingPlanReminderNotification($item, min($days, -1), 'hr'));
                $sent++;
            }
        }

        $this->info("Sent {$sent} training reminder(s).");
    }

    private function openItems()
    {
        return TrainingPlanItem::query()
            ->with(['employee.userAccount', 'employee.department.parent', 'plan'])
            // Approved trainings keep their reminders while their plan is being revised.
            ->where('approval_status', ApprovalStatus::APPROVED->value)
            ->whereIn('status', [TrainingStatus::NOT_STARTED->value, TrainingStatus::SCHEDULED->value])
            ->whereNotNull('planned_start_date');
    }

    /** @return array<int, array{0: User, 1: string}> one entry per person, most specific role first */
    private function recipients(TrainingPlanItem $item, Collection $hrUsers): array
    {
        $candidates = [
            [$item->employee?->userAccount, 'trainee'],
            [$item->employee?->hodUser(), 'hod'],
            ...$hrUsers->map(fn ($u) => [$u, 'hr'])->all(),
        ];

        $seen = [];
        $recipients = [];

        foreach ($candidates as [$user, $type]) {
            if ($user && !isset($seen[$user->id])) {
                $seen[$user->id] = true;
                $recipients[] = [$user, $type];
            }
        }

        return $recipients;
    }
}
