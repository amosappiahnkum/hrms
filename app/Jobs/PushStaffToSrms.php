<?php

namespace App\Jobs;

use App\Models\SelfService\Employee;
use App\Services\Srms\SrmsStaffSync;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/** An employee's details changed: send them to SRMS (SrmsStaffSync). Retried if SRMS is down. */
class PushStaffToSrms implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 5;

    /** @var list<int> */
    public array $backoff = [60, 300, 900, 3600];

    public function __construct(public int $employeeId, public bool $biodataUpdated = true)
    {
        $this->afterCommit();
    }

    /** Queue it for the employee, when SRMS is set up and they have a staff number. */
    public static function for(?Employee $employee, bool $biodataUpdated = true): void
    {
        if ($employee?->id && filled($employee->staff_id) && SrmsStaffSync::enabled()) {
            self::dispatch($employee->id, $biodataUpdated);
        }
    }

    public function handle(SrmsStaffSync $sync): void
    {
        $employee = Employee::withTrashed()->withoutGlobalScopes()->find($this->employeeId);
        if ($employee !== null) {
            $sync->push($employee, $this->biodataUpdated);
        }
    }
}
