<?php

namespace App\Services\Leave;

use App\Models\Config\Department;
use App\Models\SelfService\Employee;

/**
 * Who approves an employee's leave (the HOD step): the head of the nearest department above them
 * that has a head other than themselves.
 *
 * Walking up from the employee's department:
 *  - a department with no head is skipped (its parent's head approves);
 *  - departments the employee heads are skipped, and so is everything below them, so heads of
 *    departments are approved by the head of the department above, never by themselves or
 *    by someone they lead.
 */
class LeaveApprover
{
    public function for(Employee $employee): ?Employee
    {
        $chain = $this->chain($employee->department);

        // Start above the highest department in the chain the employee heads.
        $lastHeaded = null;
        foreach ($chain as $i => $department) {
            if ((int) $department->hod === (int) $employee->id) {
                $lastHeaded = $i;
            }
        }
        $candidates = $lastHeaded === null ? $chain : array_slice($chain, $lastHeaded + 1);

        foreach ($candidates as $department) {
            if ($department->hod && (int) $department->hod !== (int) $employee->id && $department->headOfDepartment) {
                return $department->headOfDepartment;
            }
        }

        return null;
    }

    /** Whether the employee heads a department on their own chain (so may have no one above them). */
    public function headsOwnChain(Employee $employee): bool
    {
        return collect($this->chain($employee->department))->contains(fn (Department $d) => (int) $d->hod === (int) $employee->id);
    }

    /** @return Department[] The employee's department, then each parent up to the top (stops on loops). */
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
}
