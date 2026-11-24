<?php

namespace App\Console\Commands;

use App\Models\SelfService\Employee;
use App\Services\Srms\SrmsStaffSync;
use Illuminate\Console\Command;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Sleep;
use Throwable;

/**
 * Sends every employee's details to SRMS once (the first fill; safe to run again). Those not in SRMS are skipped
 * there. Archived and terminated employees are sent too, so SRMS marks them as no longer working here.
 */
class PushStaffToSrmsCommand extends Command
{
    protected $signature = 'srms:push-staff';

    protected $description = 'Send every employee\'s details to SRMS (staff found there by staff number are updated)';

    public function handle(SrmsStaffSync $sync): int
    {
        if (! SrmsStaffSync::enabled()) {
            $this->error('Switch on the integrations.srms feature (TTU only) and set SRMS_API_URL and SRMS_API_KEY first.');

            return self::FAILURE;
        }

        $counts = ['updated' => 0, 'not_in_srms' => 0, 'failed' => 0];
        Employee::withTrashed()->whereNotNull('staff_id')->where('staff_id', '!=', '')
            ->with(['contactDetail', 'rank', 'jobDetail.position'])
            ->chunkById(100, function ($employees) use ($sync, &$counts): void {
                foreach ($employees as $employee) {
                    try {
                        $counts[$this->pushPaced($sync, $employee)]++;
                    } catch (Throwable $e) {
                        $counts['failed']++;
                        $this->warn("{$employee->staff_id}: {$e->getMessage()}");
                    }
                }
            });

        $this->info("Updated in SRMS: {$counts['updated']}. Not in SRMS (skipped): {$counts['not_in_srms']}. Failed: {$counts['failed']}.");

        return $counts['failed'] > 0 ? self::FAILURE : self::SUCCESS;
    }

    /** SRMS takes a limited number of calls a minute: when it says "too many", wait as long as it asks and send again. */
    private function pushPaced(SrmsStaffSync $sync, Employee $employee): string
    {
        for ($attempt = 1; ; $attempt++) {
            try {
                return $sync->push($employee, biodataUpdated: false);
            } catch (RequestException $e) {
                if ($e->response->status() !== 429 || $attempt >= 5) {
                    throw $e;
                }
                $wait = max(1, (int) ($e->response->header('Retry-After') ?: 10));
                $this->line("SRMS asked to slow down; waiting {$wait}s…");
                Sleep::for($wait)->seconds();
            }
        }
    }
}
