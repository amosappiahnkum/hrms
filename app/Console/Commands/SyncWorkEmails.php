<?php

namespace App\Console\Commands;

use App\Models\SelfService\Employee;
use App\Models\User;
use App\Services\Employee\WorkEmail;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * One-off reconciliation of `contact_details.work_email` with the login email (`users.email`).
 * Reports by default; --apply writes the login email (normalised) to both, as WorkEmail does going forward.
 */
class SyncWorkEmails extends Command
{
    protected $signature = 'employees:sync-work-emails {--apply : Write the fixes (default only reports)}';

    protected $description = 'Report, and with --apply fix, work emails that differ from the login email';

    public function handle(WorkEmail $workEmail): int
    {
        $apply = (bool) $this->option('apply');

        $duplicates = User::query()
            ->whereNotNull('employee_id')
            ->select('employee_id', DB::raw('COUNT(*) as accounts'))
            ->groupBy('employee_id')
            ->having('accounts', '>', 1)
            ->pluck('accounts', 'employee_id');

        if ($duplicates->isNotEmpty()) {
            $this->warn('Employees with more than one account (skipped: pick the account to keep by hand):');
            $this->table(['Staff ID', 'Name', 'Accounts'], Employee::query()->whereIn('id', $duplicates->keys())->get()
                ->map(fn (Employee $e) => [$e->staff_id, $e->name, User::where('employee_id', $e->id)->pluck('email')->join(', ')]));
        }

        $rows = [];
        $failed = 0;

        Employee::query()
            ->whereNotIn('id', $duplicates->keys())
            ->whereHas('userAccount')
            ->with(['userAccount', 'contactDetail'])
            ->chunkById(200, function ($employees) use (&$rows, &$failed, $apply, $workEmail) {
                foreach ($employees as $employee) {
                    $login = $employee->userAccount->email;
                    $contact = $employee->contactDetail?->work_email;
                    $target = WorkEmail::normalize($login) ?? WorkEmail::normalize($contact);

                    if (!$target || ($login === $target && $contact === $target)) {
                        continue;
                    }

                    $kind = WorkEmail::normalize($login) === WorkEmail::normalize($contact) ? 'case/spaces' : 'different';
                    $result = $apply ? 'fixed' : 'would fix';

                    if ($apply) {
                        try {
                            $workEmail->change($employee, $target);
                        } catch (ValidationException $e) {
                            $result = 'skipped: ' . collect($e->errors())->flatten()->first();
                            $failed++;
                        }
                    }

                    $rows[] = [$employee->staff_id, $employee->name, $login ?: '—', $contact ?: '—', $kind, $result];
                }
            });

        if (!$rows) {
            $this->info('Every work email matches its login email.');

            return self::SUCCESS;
        }

        $this->table(['Staff ID', 'Name', 'Login email', 'Contact work email', 'Mismatch', 'Result'], $rows);
        $this->line(count($rows) . ' mismatched' . ($failed ? ", {$failed} skipped" : '') . '.');

        if (!$apply) {
            $this->line('The login email wins. Correct any row where it is the wrong one first, then rerun with --apply.');
        }

        return self::SUCCESS;
    }
}
