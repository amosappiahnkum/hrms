<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Creates the permissions the API routes and controllers are gated by, and grants each one to the roles
 * that already had that access through role checks, so the switch changes nobody's access.
 * super-admin passes every permission check through Gate::before (AppServiceProvider).
 *
 * Roles that don't exist are skipped; existing permissions are reused, never duplicated.
 */
return new class extends Migration {
    /** group => [permission => roles] */
    private const PERMISSIONS = [
        'Employee' => [
            'view-employee'            => ['hr'],
            'add-employee'             => ['hr'],
            'edit-employee'            => ['hr'],
            'delete-employee'          => ['hr'],
            'export-employee'          => ['hr'],
            'terminate-employee'       => ['hr'],
            'manage-employee-accounts' => ['hr'],
            'approve-employee-update'  => ['hr'],
        ],
        'Department' => [
            'add-department'    => ['hr'],
            'edit-department'   => ['hr'],
            'delete-department' => ['hr'],
        ],
        'Positions' => [
            'manage-positions' => ['hr'],
        ],
        'Leave Types' => [
            'add-leave-types'    => ['hr'],
            'edit-leave-types'   => ['hr'],
            'delete-leave-types' => ['hr'],
        ],
        'Leave Management' => [
            'finalize-leave'        => ['hr'],
            'view-all-leave'        => ['hr'],
            'view-leave-analytics'  => ['hr'],
            'manage-leave-balances' => ['hr'],
        ],
        // Previously implied by the hod role itself (Controller::isSupervisor).
        'Leave Request' => [
            'approve-leave-request' => ['hod'],
            'decline-leave-request' => ['hod'],
        ],
        'Certifications' => [
            'manage-certifications' => ['hr'],
        ],
        'Recruitment' => [
            'view-job-opening'    => ['hr'],
            'add-job-opening'     => ['hr'],
            'edit-job-opening'    => ['hr'],
            'delete-job-opening'  => ['hr'],
            'manage-candidates'   => ['hr'],
            'manage-applications' => ['hr'],
            'schedule-interview'  => ['hr'],
            'make-offer'          => ['hr'],
            'hire-candidate'      => ['hr'],
        ],
        'Appraisal' => [
            'manage-appraisal-templates' => ['hr'],
            'manage-appraisal-windows'   => ['hr', 'appraisal_officer'],
            'finalize-appraisals'        => ['hr'],
            'view-hr-appraisals'         => ['appraisal_officer'],
            'finalize-hr-appraisals'     => ['appraisal_officer'],
        ],
        'Training' => [
            'manage-training'       => ['hr', 'training_officer'],
            'view-training-reports' => ['hr', 'training_officer'],
        ],
        'Question Bank' => [
            'manage-question-bank' => ['hr'],
        ],
        'Policy Documents' => [
            'manage-policy-documents' => ['hr'],
        ],
        'Dynamic Forms' => [
            'manage-dynamic-forms' => ['hr'],
        ],
        'Communication' => [
            'send-quick-email'        => ['hr'],
            'resolve-support-tickets' => [],
        ],
    ];

    public function up(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $roles = Role::query()->where('guard_name', 'web')->get()->keyBy('name');

        foreach (self::PERMISSIONS as $group => $permissions) {
            foreach ($permissions as $name => $roleNames) {
                $permission = Permission::query()
                    ->where('name', $name)
                    ->where('guard_name', 'web')
                    ->first();

                if (!$permission) {
                    $permission = new Permission();
                    $permission->name = $name;
                    $permission->guard_name = 'web';
                    $permission->group = $group;
                    $permission->uuid = (string) Str::uuid();
                    $permission->save();
                }

                foreach ($roleNames as $roleName) {
                    $roles->get($roleName)?->givePermissionTo($permission);
                }
            }
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        // Permissions may have been granted to users since; leave them in place.
    }
};
