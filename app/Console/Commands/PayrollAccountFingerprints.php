<?php

namespace App\Console\Commands;

use App\Models\Payroll\EmployeePayProfile;
use Illuminate\Console\Command;

/**
 * Recompute the fingerprints of bank and mobile money numbers (after PAYMENT_FINGERPRINT_KEY is set or changed, or for
 * details saved before fingerprints existed), then list employees whose current details share an account.
 */
class PayrollAccountFingerprints extends Command
{
    protected $signature = 'payroll:account-fingerprints {--check : only list shared accounts, change nothing}';

    protected $description = 'Recompute payment number fingerprints and list employees paid into the same account';

    public function handle(): int
    {
        if (!$this->option('check')) {
            $count = 0;
            EmployeePayProfile::query()->chunkById(200, function ($profiles) use (&$count) {
                foreach ($profiles as $p) {
                    // Saving recomputes them (see EmployeePayProfile::booted); quietly: nothing else changes.
                    $p->saveQuietly();
                    $count++;
                }
            });
            $this->info("Fingerprints recomputed for {$count} pay details.");
        }

        $shared = EmployeePayProfile::inForceOn(now())->with('employee')->get()
            ->groupBy(fn ($p) => EmployeePayProfile::paymentKey($p->only(['payment_method', 'bank_id', 'account_number', 'mobile_money_number'])) ?? "none:{$p->id}")
            ->filter(fn ($group) => $group->count() > 1);
        if ($shared->isEmpty()) {
            $this->info('No two employees are paid into the same account.');

            return self::SUCCESS;
        }
        $this->warn('Employees paid into the same account (correct their pay details):');
        foreach ($shared as $group) {
            $p = $group->first();
            $account = $p->payment_method === 'bank' ? "{$p->bank_name} …" . substr((string) $p->account_number, -4) : 'Mobile money …' . substr((string) $p->mobile_money_number, -4);
            $this->line("  {$account}: " . $group->map(fn ($p) => trim(preg_replace('/\s+/', ' ', (string) $p->employee?->name)) . " ({$p->employee?->staff_id})")->join(', '));
        }

        return self::FAILURE;
    }
}
