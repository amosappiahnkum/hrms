<?php

namespace App\Enums\Payroll;

/** Who approves a step, resolved for the employee the request is about. */
enum ApproverType: string
{
    case SUPERVISOR = 'supervisor';
    case DEPARTMENT_HEAD = 'department_head';
    case PARENT_DEPARTMENT_HEAD = 'parent_department_head';
    case ROLE = 'role';
    case PERMISSION = 'permission';
    case USERS = 'users';

    public function label(): string
    {
        return match ($this) {
            self::SUPERVISOR             => "The employee's supervisor",
            self::DEPARTMENT_HEAD        => 'Head of their department',
            self::PARENT_DEPARTMENT_HEAD => 'Head of the parent department',
            self::ROLE                   => 'Everyone with a role',
            self::PERMISSION             => 'Everyone with a permission',
            self::USERS                  => 'Specific people',
        };
    }

    /** Types that need a value: a role, a permission or people. */
    public function needsValue(): bool
    {
        return in_array($this, [self::ROLE, self::PERMISSION, self::USERS], true);
    }
}
