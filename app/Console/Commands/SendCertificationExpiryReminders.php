<?php

namespace App\Console\Commands;

use App\Enums\Competency\DevelopmentMethod;
use App\Enums\Competency\DevelopmentStatus;
use App\Models\Competency\DevelopmentAction;
use App\Models\EmployeeCertification;
use App\Services\Competency\CertificationStatusService;
use App\Models\User;
use App\Notifications\CertificationExpiryNotification;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class SendCertificationExpiryReminders extends Command
{
    protected $signature = 'certifications:send-expiry-reminders';

    protected $description = 'Send escalating expiry reminders for employee certifications (6 months → expiry)';

    /**
     * Escalation windows (days remaining → send frequency in days).
     * The command runs daily; frequency controls whether we actually send today.
     */
    private const WINDOWS = [
        ['min' => 8,  'max' => 30,  'every' => 2],   // 1 month → 1 week:  every 2 days
        ['min' => 31, 'max' => 180, 'every' => 7],    // 6 months → 1 month: weekly
    ];
    // Days remaining <= 7: always send (daily)

    public function handle(): void
    {
        $today = Carbon::today();
        $this->createRenewalNeeds($today);

        $certifications = EmployeeCertification::with(['employee.userAccount', 'provider'])
            ->where('does_not_expire', false)
            ->whereNotNull('expiry_date')
            ->whereBetween('expiry_date', [$today->toDateString(), $today->copy()->addDays(180)->toDateString()])
            ->whereNull('deleted_at')
            ->get();

        if ($certifications->isEmpty()) {
            $this->info('No certifications in the reminder window today.');
            return;
        }

        // Fetch certification managers (plus super-admins) once — they receive all reminders
        $hrUsers = User::permission('manage-certifications')->get()
            ->merge(User::role('super-admin')->get())
            ->unique('id');

        $sent = 0;

        foreach ($certifications as $cert) {
            $daysRemaining = (int) $today->diffInDays(Carbon::parse($cert->expiry_date), false);

            if ($daysRemaining < 0) {
                continue; // already expired — skip (expired alert is a separate concern)
            }

            if (!$this->shouldSendToday($daysRemaining)) {
                continue;
            }

            // Notify the employee
            $employeeUser = $cert->employee?->userAccount;
            if ($employeeUser) {
                $employeeUser->notify(
                    new CertificationExpiryNotification($cert, $daysRemaining, 'employee')
                );
            }

            // Notify all HR / super-admin users
            foreach ($hrUsers as $hrUser) {
                $hrUser->notify(
                    new CertificationExpiryNotification($cert, $daysRemaining, 'hr')
                );
            }

            $this->line("  Sent ({$daysRemaining}d): {$cert->employee?->name} — {$cert->title}");
            $sent++;
        }

        $this->info("Done. Reminders dispatched for {$sent} certification(s).");
        Log::info("SendCertificationExpiryReminders: {$sent} dispatched on " . $today->toDateString());
    }

    /**
     * A typed certificate that evidences a competency and expires within the renewal window (or has
     * expired) becomes a training need: an open "certification" development action, which lands in
     * the training team's needs queue. Skipped once renewed (a later certificate of the same type) or
     * while a renewal action is already open.
     */
    private function createRenewalNeeds(Carbon $today): void
    {
        if (!feature('competency.enabled')) {
            return;
        }

        $created = 0;
        $expiring = EmployeeCertification::with(['type', 'employee'])
            ->where('does_not_expire', false)
            ->whereNotNull('expiry_date')
            ->whereDate('expiry_date', '<=', $today->copy()->addDays(CertificationStatusService::EXPIRING_DAYS))
            ->whereHas('type', fn ($t) => $t->whereNotNull('competency_id')->whereHas('competency'))
            ->whereHas('employee')
            ->orderByDesc('expiry_date')
            ->get()
            ->unique(fn ($c) => "{$c->employee_id}:{$c->certification_type_id}");

        foreach ($expiring as $cert) {
            $renewed = EmployeeCertification::where('employee_id', $cert->employee_id)
                ->where('certification_type_id', $cert->certification_type_id)
                ->where(fn ($q) => $q->where('does_not_expire', true)->orWhereDate('expiry_date', '>', $cert->expiry_date))
                ->exists();
            $open = DevelopmentAction::open()
                ->where('employee_id', $cert->employee_id)
                ->where('competency_id', $cert->type->competency_id)
                ->where('method', DevelopmentMethod::CERTIFICATION)
                ->exists();

            if ($renewed || $open) {
                continue;
            }

            DevelopmentAction::create([
                'employee_id'   => $cert->employee_id,
                'competency_id' => $cert->type->competency_id,
                'method'        => DevelopmentMethod::CERTIFICATION,
                'status'        => DevelopmentStatus::PLANNED,
                'description'   => "Renew {$cert->type->name} (" . ($cert->expiry_date->isPast() ? 'expired' : 'expires') . " {$cert->expiry_date->format('j M Y')})",
                'due_on'        => $cert->expiry_date->toDateString(),
            ]);
            $created++;
        }

        if ($created) {
            $this->info("Created {$created} certificate renewal need(s).");
        }
    }

    /**
     * Decide whether today is a send day based on the window the cert falls into.
     *
     * ≤ 7 days:   send every day
     * 8–30 days:  send every 2 days
     * 31–180 days: send weekly (every 7 days)
     */
    private function shouldSendToday(int $daysRemaining): bool
    {
        if ($daysRemaining <= 7) {
            return true;
        }

        foreach (self::WINDOWS as $window) {
            if ($daysRemaining >= $window['min'] && $daysRemaining <= $window['max']) {
                // Send when daysRemaining is exactly divisible by the frequency,
                // anchoring to the exact day counts so reminders land predictably.
                return $daysRemaining % $window['every'] === 0;
            }
        }

        return false;
    }
}
