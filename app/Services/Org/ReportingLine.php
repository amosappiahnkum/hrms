<?php

namespace App\Services\Org;

use App\Models\Config\Department;
use App\Models\SelfService\Employee;
use Illuminate\Support\Collection;

/**
 * Who an employee reports to, and who is above them: one answer for leave, appraisals and the approval workflows.
 *
 * - **Supervisor**: the person set as their "Reports to", else the head of their unit.
 * - **Head of their unit**: the head of the nearest department above them that has a head other than themselves.
 *   Walking up from their department, a department with no head is skipped (its parent's head counts), and so are
 *   the departments they head and everything below them, so a head is supervised by the head above, never by
 *   themselves or someone they lead.
 * - **Heads above**: each head up the tree from there, nearest first (for approval chains).
 */
class ReportingLine
{
    /** Their supervisor: "Reports to" when set, else the head of their unit. */
    public function supervisorOf(Employee $employee): ?Employee
    {
        return $this->reportsTo($employee) ?? $this->unitHeadOf($employee);
    }

    /** The person set as their "Reports to" (still employed), or null for the default. */
    public function reportsTo(Employee $employee): ?Employee
    {
        $set = $employee->reports_to_id ? $employee->reportsTo : null;

        return $set && $set->id !== $employee->id ? $set : null;
    }

    /** The head of their unit (see the class notes), or null at the top of the tree. */
    public function unitHeadOf(Employee $employee): ?Employee
    {
        return $this->headsAbove($employee, ['stop' => 'count', 'count' => 1])->first();
    }

    /**
     * The heads above the employee, nearest first: each department's head walking up from theirs, skipping
     * departments with no head, the employee, the departments they head (and everything below them), and anyone
     * already listed. The chain follows the employee's department even when "Reports to" points elsewhere.
     *
     * @param array{stop?: 'top'|'count'|'level', count?: int, level?: int} $rule where to stop: at the top (default), after
     *        `count` heads, or at the first department at `level` or above (e.g. the Directorate), its head included
     * @return Collection<int, Employee>
     */
    public function headsAbove(Employee $employee, array $rule = []): Collection
    {
        return $this->chainAbove($employee, $rule)->pluck('head');
    }

    /**
     * As headsAbove(), with the department each head heads on the employee's chain (e.g. to name approval steps).
     *
     * @return Collection<int, array{head: Employee, department: Department}>
     */
    public function chainAbove(Employee $employee, array $rule = []): Collection
    {
        $chain = $this->chain($employee->department);
        // Start above the highest department on the chain the employee heads.
        $lastHeaded = null;
        foreach ($chain as $i => $department) {
            if ((int) $department->hod === (int) $employee->id) {
                $lastHeaded = $i;
            }
        }
        $limit = ($rule['stop'] ?? 'top') === 'count' ? max(0, (int) ($rule['count'] ?? 1)) : PHP_INT_MAX;
        $stopLevel = ($rule['stop'] ?? 'top') === 'level' ? (int) ($rule['level'] ?? 0) : null;
        $links = collect();

        foreach ($lastHeaded === null ? $chain : array_slice($chain, $lastHeaded + 1) as $department) {
            if ($links->count() >= $limit) {
                break;
            }
            $head = $department->hod ? $department->headOfDepartment : null;
            if ($head && $head->id !== $employee->id && !$links->contains(fn ($l) => $l['head']->id === $head->id)) {
                $links->push(['head' => $head, 'department' => $department]);
            }
            if ($stopLevel !== null && $department->level !== null && $department->level <= $stopLevel) {
                break;
            }
        }

        return $links;
    }

    /** Whether the employee heads a department on their own chain (so may have no one above them). */
    public function headsOwnChain(Employee $employee): bool
    {
        return collect($this->chain($employee->department))->contains(fn (Department $d) => (int) $d->hod === (int) $employee->id);
    }

    /**
     * The people whose supervisor is this employee: those set to report to them, and those in the departments they
     * head (and below) whose unit head they are and who have no "Reports to" of their own.
     *
     * @return Collection<int, Employee> each with `reports_by_default` set (true: they're the unit head, not set)
     */
    public function reportsOf(Employee $supervisor): Collection
    {
        $explicit = Employee::where('reports_to_id', $supervisor->id)->whereKeyNot($supervisor->id)->with('department')->get()
            ->map(fn (Employee $e) => tap($e)->setAttribute('reports_by_default', false));

        $departments = $this->subtree(Department::where('hod', $supervisor->id)->pluck('id')->all());
        $default = Employee::whereIn('department_id', $departments)->whereKeyNot($supervisor->id)->with('department', 'reportsTo')->get()
            ->reject(fn (Employee $e) => $this->reportsTo($e) !== null)
            ->filter(fn (Employee $e) => $this->unitHeadOf($e)?->id === $supervisor->id)
            ->map(fn (Employee $e) => tap($e)->setAttribute('reports_by_default', true));

        return $explicit->concat($default)->unique('id')->sortBy('first_name')->values();
    }

    /**
     * Set (or, with null, clear back to the default) who the employee reports to. Refused when it would put
     * someone above themselves. Changes are kept in the activity log (by the caller).
     */
    public function setReportsTo(Employee $employee, ?Employee $supervisor): void
    {
        if ($supervisor && $supervisor->id === $employee->id) {
            throw new \App\Exceptions\UserFacingException('Someone can\'t report to themselves.');
        }
        // Following who the new supervisor reports to (as set) must not lead back to the employee.
        for ($above = $supervisor, $seen = []; $above && !isset($seen[$above->id]); $above = $this->reportsTo($above)) {
            if ($above->id === $employee->id) {
                throw new \App\Exceptions\UserFacingException("{$supervisor->name} reports (through others) to {$employee->name}, so it would be a loop.");
            }
            $seen[$above->id] = true;
        }

        $employee->update(['reports_to_id' => $supervisor?->id]);
        $employee->unsetRelation('reportsTo');
    }

    /** @return Department[] the department, then each parent up to the top (stops on loops) */
    private function chain(?Department $department): array
    {
        $chain = [];
        $seen = [];
        while ($department && !isset($seen[$department->id])) {
            $seen[$department->id] = true;
            $chain[] = $department;
            $department = $department->parent;
        }

        return $chain;
    }

    /** These departments and every department below them. */
    private function subtree(array $ids): array
    {
        $all = $ids;
        for ($level = $ids; $level; ) {
            $level = Department::whereIn('parent_department_id', $level)->whereNotIn('id', $all)->pluck('id')->all();
            $all = array_merge($all, $level);
        }

        return $all;
    }
}
