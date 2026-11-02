<?php

namespace App\Http\Controllers;

use App\Exceptions\UserFacingException;
use App\Helpers\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Roles and permissions, for super-admins: which permissions each role has, and custom roles and permissions
 * (e.g. an "operations-manager" role for an approval step). Built-in ones can't be renamed or removed, and
 * super-admin can't be changed (it passes every check anyway).
 */
class AccessControlController extends Controller
{
    private const NAME = ['required', 'string', 'max:100', 'regex:/^[a-z0-9]+(-[a-z0-9]+)*$/'];
    private const NAME_MESSAGE = 'Use lowercase letters, numbers and dashes, e.g. operations-manager.';

    public function index(): JsonResponse
    {
        $roles = Role::where('guard_name', 'web')->with('permissions:id,name')->orderByDesc('is_custom')->orderBy('name')->get();
        $holders = $this->holders();
        $permissions = Permission::where('guard_name', 'web')->withCount('roles')->orderBy('group')->orderBy('name')->get();

        return ApiResponse::success([
            'roles'       => $roles->map(fn (Role $r) => self::role($r, (int) ($holders[$r->id] ?? 0)))->values(),
            'permissions' => $permissions->groupBy(fn ($p) => $p->group ?: 'Other')
                ->map(fn ($list, $group) => ['group' => $group, 'permissions' => $list->map(fn ($p) => [
                    'uuid' => $p->uuid, 'name' => $p->name, 'is_custom' => (bool) $p->is_custom, 'roles' => $p->roles_count,
                ])->values()])->values(),
        ]);
    }

    public function storeRole(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name'          => [...self::NAME, Rule::unique(config('permission.table_names.roles'), 'name')->where('guard_name', 'web')],
            'permissions'   => ['nullable', 'array'],
            'permissions.*' => ['string', Rule::exists(config('permission.table_names.permissions'), 'name')->where('guard_name', 'web')],
        ], ['name.regex' => self::NAME_MESSAGE, 'name.unique' => 'There is already a role with this name.']);

        $role = DB::transaction(function () use ($data) {
            $role = new Role();
            $role->name = $data['name'];
            $role->guard_name = 'web';
            $role->uuid = (string) Str::uuid();
            $role->is_custom = true;
            $role->save();
            $role->syncPermissions($data['permissions'] ?? []);

            return $role;
        });
        $this->changed('role_created', $role, ['permissions' => $data['permissions'] ?? []]);

        return ApiResponse::success(self::role($role->load('permissions:id,name'), 0), 'Role added.', 201);
    }

    /** Rename a custom role, and set any role's permissions (not super-admin's). */
    public function updateRole(Request $request, string $uuid): JsonResponse
    {
        $role = $this->findRole($uuid);
        if ($role->name === 'super-admin') {
            throw new UserFacingException('super-admin has every permission and can\'t be changed.');
        }
        $data = $request->validate([
            'name'          => ['sometimes', ...self::NAME, Rule::unique(config('permission.table_names.roles'), 'name')->where('guard_name', 'web')->ignore($role->id)],
            'permissions'   => ['sometimes', 'array'],
            'permissions.*' => ['string', Rule::exists(config('permission.table_names.permissions'), 'name')->where('guard_name', 'web')],
        ], ['name.regex' => self::NAME_MESSAGE, 'name.unique' => 'There is already a role with this name.']);
        if (isset($data['name']) && $data['name'] !== $role->name && !$role->is_custom) {
            throw new UserFacingException('Built-in roles can\'t be renamed: the system refers to them by name.');
        }

        $before = $role->permissions->pluck('name')->sort()->values()->all();
        DB::transaction(function () use ($role, $data) {
            if (isset($data['name'])) {
                $role->update(['name' => $data['name']]);
            }
            if (array_key_exists('permissions', $data)) {
                $role->syncPermissions($data['permissions']);
            }
        });
        $after = $role->fresh('permissions')->permissions->pluck('name')->sort()->values()->all();
        $this->changed('role_updated', $role, [
            'added' => array_values(array_diff($after, $before)), 'removed' => array_values(array_diff($before, $after)),
        ]);

        return ApiResponse::success(self::role($role->fresh()->load('permissions:id,name'), (int) ($this->holders()[$role->id] ?? 0)), 'Role saved.');
    }

    public function destroyRole(string $uuid): JsonResponse
    {
        $role = $this->findRole($uuid);
        if (!$role->is_custom) {
            throw new UserFacingException('Built-in roles can\'t be removed.');
        }
        if (($count = (int) ($this->holders()[$role->id] ?? 0)) > 0) {
            throw new UserFacingException("{$count} " . str('user')->plural($count) . " still have this role. Take it off them first.");
        }
        $this->changed('role_deleted', $role, ['permissions' => $role->permissions->pluck('name')->all()]);
        $role->delete();
        $this->forget();

        return ApiResponse::success(null, 'Role removed.');
    }

    /**
     * A custom permission. It does nothing until something checks it: an approval step for "everyone with a
     * permission", a role, or a feature built to use it.
     */
    public function storePermission(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name'  => [...self::NAME, Rule::unique(config('permission.table_names.permissions'), 'name')->where('guard_name', 'web')],
            'group' => ['required', 'string', 'max:60'],
        ], ['name.regex' => self::NAME_MESSAGE, 'name.unique' => 'There is already a permission with this name.']);

        $permission = new Permission();
        $permission->name = $data['name'];
        $permission->group = $data['group'];
        $permission->guard_name = 'web';
        $permission->uuid = (string) Str::uuid();
        $permission->is_custom = true;
        $permission->save();
        $this->changed('permission_created', $permission, ['group' => $data['group']]);

        return ApiResponse::success(['uuid' => $permission->uuid, 'name' => $permission->name, 'group' => $permission->group, 'is_custom' => true], 'Permission added.', 201);
    }

    public function destroyPermission(string $uuid): JsonResponse
    {
        $permission = Permission::where('uuid', $uuid)->where('guard_name', 'web')->firstOrFail();
        if (!$permission->is_custom) {
            throw new UserFacingException('Built-in permissions can\'t be removed: the system checks them.');
        }
        $this->changed('permission_deleted', $permission, ['roles' => $permission->roles()->pluck('name')->all()]);
        $permission->delete();
        $this->forget();

        return ApiResponse::success(null, 'Permission removed.');
    }

    public static function role(Role $r, int $holders): array
    {
        return [
            'uuid'        => $r->uuid,
            'name'        => $r->name,
            'is_custom'   => (bool) $r->is_custom,
            // super-admin passes every check, whatever it's given.
            'locked'      => $r->name === 'super-admin',
            'users'       => $holders,
            'permissions' => $r->permissions->pluck('name')->sort()->values(),
        ];
    }

    /** How many people hold each role: role id => count. */
    private function holders(): \Illuminate\Support\Collection
    {
        return DB::table(config('permission.table_names.model_has_roles'))->selectRaw('role_id, count(*) as n')->groupBy('role_id')->pluck('n', 'role_id');
    }

    private function findRole(string $uuid): Role
    {
        return Role::where('uuid', $uuid)->where('guard_name', 'web')->with('permissions:id,name')->firstOrFail();
    }

    private function changed(string $event, $subject, array $properties): void
    {
        $this->forget();
        activity('security')->causedBy(Auth::user())->performedOn($subject)->event($event)
            ->withProperties($properties + ['name' => $subject->name])->log(str_replace('_', ' ', ucfirst($event)) . ": {$subject->name}");
    }

    private function forget(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
