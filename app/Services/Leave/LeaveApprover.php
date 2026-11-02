<?php

namespace App\Services\Leave;

use App\Models\SelfService\Employee;
use App\Services\Org\ReportingLine;
use Illuminate\Support\Collection;

/**
 * Who approves an employee's leave before HR: their supervisor ("Reports to", else the head of their unit), then the
 * heads above them as the setting `leave.approval_chain` says (default: the supervisor only). See ReportingLine.
 */
class LeaveApprover
{
    public function __construct(private readonly ReportingLine $line)
    {
    }

    /** The first approver: their supervisor. */
    public function for(Employee $employee): ?Employee
    {
        return $this->line->supervisorOf($employee);
    }

    /**
     * Everyone who approves, in order: the supervisor, then heads above (leaving out the supervisor and anyone
     * listed), stopping as the setting says. Empty when there's nobody above them.
     *
     * @return Collection<int, Employee>
     */
    public function chainFor(Employee $employee): Collection
    {
        $supervisor = $this->for($employee);
        if (!$supervisor) {
            return collect();
        }
        $rule = self::rule();
        $heads = $this->line->chainAbove($employee, $rule['stop'] === 'level' ? $rule : [])->pluck('head')
            ->reject(fn (Employee $h) => $h->id === $supervisor->id);
        if ($rule['stop'] === 'count') {
            $heads = $heads->take($rule['count']);
        }

        return collect([$supervisor])->concat($heads)->unique('id')->values();
    }

    /** The setting, checked: {stop: count, count: 0–10} | {stop: level, level} | {stop: top}. */
    public static function rule(): array
    {
        $rule = (array) setting('leave.approval_chain', ['stop' => 'count', 'count' => 0]);
        $stop = in_array($rule['stop'] ?? null, ['top', 'count', 'level'], true) ? $rule['stop'] : 'count';

        return ['stop' => $stop, 'count' => max(0, min(10, (int) ($rule['count'] ?? 0))), 'level' => (int) ($rule['level'] ?? 0)];
    }

    /** Whether the employee heads a department on their own chain (so may have no one above them). */
    public function headsOwnChain(Employee $employee): bool
    {
        return $this->line->headsOwnChain($employee);
    }
}
