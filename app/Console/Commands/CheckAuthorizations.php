<?php

namespace App\Console\Commands;

use App\Enums\Competency\AuthorizationStatus;
use App\Models\Competency\EmployeeAuthorization;
use App\Models\SelfService\Employee;
use App\Services\Competency\AuthorizationService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Keeps the authorization register true over time: grants past their valid-until date expire (and
 * those expiring within 30 days are flagged), and authorizations whose certificates have lapsed are
 * suspended. Re-ratings are checked as they happen.
 */
class CheckAuthorizations extends Command
{
    protected $signature = 'competency:check-authorizations';

    protected $description = 'Expire, flag and suspend authorizations that no longer hold';

    public function handle(AuthorizationService $authorizations): int
    {
        if (!feature('competency.enabled')) {
            $this->info('The competency matrix is switched off.');
            return self::SUCCESS;
        }

        [$expired, $expiring] = $authorizations->expire(Carbon::today());

        $suspended = 0;
        $employeeIds = EmployeeAuthorization::where('status', AuthorizationStatus::AUTHORIZED)->distinct()->pluck('employee_id');
        foreach (Employee::whereIn('id', $employeeIds)->get() as $employee) {
            $suspended += $authorizations->recheck($employee);
        }

        $this->info("Expired {$expired}, flagged {$expiring} expiring, suspended {$suspended}.");

        return self::SUCCESS;
    }
}
