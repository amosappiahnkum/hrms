<?php

namespace App\Http\Controllers\Payroll;

use App\Enums\Payroll\ApprovalProcess;
use App\Enums\Payroll\ApproverType;
use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Models\Payroll\ApprovalWorkflow;
use App\Models\SelfService\Employee;
use App\Models\User;
use App\Services\Payroll\ApprovalWorkflowService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/** The approval chains HR configures for overtime, loans, time inputs and pay runs. */
class ApprovalWorkflowController extends Controller
{
    public function __construct(private readonly ApprovalWorkflowService $workflows)
    {
    }

    public function index(): JsonResponse
    {
        $workflows = ApprovalWorkflow::with('steps')->orderBy('process')->orderByDesc('is_default')->orderBy('name')->get();
        $users = User::whereIn('id', $workflows->flatMap(fn ($w) => $w->steps)->where('approver_type', ApproverType::USERS)->flatMap(fn ($s) => (array) $s->approver_value))->get()->keyBy('id');

        return ApiResponse::success([
            'workflows' => $workflows->map(fn ($w) => $this->row($w, $users))->values(),
            'options'   => [
                'processes'      => collect(ApprovalProcess::cases())->map(fn ($p) => [
                    'value' => $p->value, 'label' => $p->label(),
                    'adjustable' => collect($p->adjustable())->map(fn ($label, $value) => ['value' => $value, 'label' => $label])->values(),
                ]),
                'approver_types' => collect(ApproverType::cases())->map(fn ($t) => ['value' => $t->value, 'label' => $t->label(), 'needs_value' => $t->needsValue()]),
                'roles'          => Role::where('guard_name', 'web')->orderBy('name')->pluck('name'),
                'permissions'    => Permission::where('guard_name', 'web')->orderBy('name')->pluck('name'),
            ],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $workflow = $this->save(new ApprovalWorkflow(), $this->validated($request));

        return ApiResponse::success($this->row($workflow), 'Workflow added.', 201);
    }

    public function update(Request $request, ApprovalWorkflow $approvalWorkflow): JsonResponse
    {
        $workflow = $this->save($approvalWorkflow, $this->validated($request, $approvalWorkflow));

        return ApiResponse::success($this->row($workflow), 'Workflow saved. Requests already under way keep the steps they started with.');
    }

    /** Requests under way keep their steps; new ones use the process's default (or HR). */
    public function destroy(ApprovalWorkflow $approvalWorkflow): JsonResponse
    {
        $approvalWorkflow->delete();

        return ApiResponse::success(null, 'Workflow removed.');
    }

    /** Who would approve each step for a given employee. */
    public function preview(Request $request, ApprovalWorkflow $approvalWorkflow): JsonResponse
    {
        $employee = Employee::where('uuid', $request->validate(['employee_uuid' => ['required', 'string']])['employee_uuid'])->firstOrFail();

        return ApiResponse::success($approvalWorkflow->steps->map(function ($step) use ($employee) {
            $users = $this->workflows->resolve($step, $employee);

            return [
                'name'      => $step->name,
                'approvers' => $users->map(fn ($u) => $u->name)->values(),
                // Resolves to nobody: HR is asked instead.
                'fallback'  => $users->isEmpty(),
            ];
        })->values());
    }

    /** People to pick for a "specific people" step. */
    public function people(Request $request): JsonResponse
    {
        $search = $request->string('search')->toString();

        return ApiResponse::success(User::query()
            ->when($search, fn ($q) => $q->where(fn ($w) => $w->where('name', 'like', "%{$search}%")->orWhere('email', 'like', "%{$search}%")))
            ->orderBy('name')->limit(20)->get()
            ->map(fn ($u) => ['uuid' => $u->uuid, 'name' => $u->name, 'email' => $u->email]));
    }

    private function validated(Request $request, ?ApprovalWorkflow $workflow = null): array
    {
        $data = $request->validate([
            'process'                => [$workflow ? 'prohibited' : 'required', Rule::enum(ApprovalProcess::class)],
            'name'                   => ['required', 'string', 'max:255'],
            'is_default'             => ['sometimes', 'boolean'],
            'distinct_approvers'     => ['sometimes', 'boolean'],
            'steps'                  => ['required', 'array', 'min:1', 'max:10'],
            'steps.*.name'           => ['required', 'string', 'max:100'],
            'steps.*.approver_type'  => ['required', Rule::enum(ApproverType::class)],
            'steps.*.approver_value' => ['nullable'],
            'steps.*.can_adjust'     => ['nullable', 'array'],
        ], ['steps.required' => 'Add at least one step.']);

        $process = $workflow?->process ?? ApprovalProcess::from($data['process']);
        foreach ($data['steps'] as $i => &$step) {
            $type = ApproverType::from($step['approver_type']);
            $step['approver_value'] = match ($type) {
                ApproverType::ROLE       => $this->named($step['approver_value'] ?? null, Role::class, "steps.{$i}.approver_value"),
                ApproverType::PERMISSION => $this->named($step['approver_value'] ?? null, Permission::class, "steps.{$i}.approver_value"),
                ApproverType::USERS      => $this->users((array) ($step['approver_value'] ?? []), "steps.{$i}.approver_value"),
                default                  => null,
            };
            $step['can_adjust'] = array_values(array_intersect($step['can_adjust'] ?? [], array_keys($process->adjustable())));
        }
        $data['process'] = $process;

        return $data;
    }

    private function named(mixed $value, string $model, string $field): array
    {
        $name = is_array($value) ? ($value[0] ?? null) : $value;
        if (!$name || !$model::where('name', $name)->where('guard_name', 'web')->exists()) {
            throw ValidationException::withMessages([$field => 'Choose one from the list.']);
        }

        return [$name];
    }

    /** User uuids in, ids stored. */
    private function users(array $uuids, string $field): array
    {
        $ids = User::whereIn('uuid', $uuids)->pluck('id')->all();
        if (!$ids) {
            throw ValidationException::withMessages([$field => 'Choose at least one person.']);
        }

        return $ids;
    }

    private function save(ApprovalWorkflow $workflow, array $data): ApprovalWorkflow
    {
        return DB::transaction(function () use ($workflow, $data) {
            $process = $data['process'];
            $makeDefault = ($data['is_default'] ?? false)
                || !ApprovalWorkflow::where('process', $process)->where('is_default', true)->whereKeyNot($workflow->id)->exists();

            if ($makeDefault) {
                ApprovalWorkflow::where('process', $process)->whereKeyNot($workflow->id)->update(['is_default' => false]);
            }

            $workflow->fill([
                'process'            => $process,
                'name'               => $data['name'],
                'is_default'         => $makeDefault,
                'distinct_approvers' => $data['distinct_approvers'] ?? $workflow->distinct_approvers ?? false,
            ])->save();

            $workflow->steps()->delete();
            foreach (array_values($data['steps']) as $i => $step) {
                $workflow->steps()->create($step + ['position' => $i + 1]);
            }

            return $workflow->fresh('steps');
        });
    }

    private function row(ApprovalWorkflow $w, $users = null): array
    {
        $users ??= User::whereIn('id', $w->steps->where('approver_type', ApproverType::USERS)->flatMap(fn ($s) => (array) $s->approver_value))->get()->keyBy('id');

        return [
            'uuid'               => $w->uuid,
            'process'            => ['value' => $w->process->value, 'label' => $w->process->label()],
            'name'               => $w->name,
            'is_default'         => $w->is_default,
            'distinct_approvers' => $w->distinct_approvers,
            'steps'              => $w->steps->map(fn ($s) => [
                'name'           => $s->name,
                'approver_type'  => ['value' => $s->approver_type->value, 'label' => $s->approver_type->label()],
                // Roles and permissions by name; people as {uuid, name}.
                'approver_value' => $s->approver_type === ApproverType::USERS
                    ? collect((array) $s->approver_value)->map(fn ($id) => $users->get($id))->filter()->map(fn ($u) => ['uuid' => $u->uuid, 'name' => $u->name])->values()
                    : ($s->approver_value[0] ?? null),
                'can_adjust'     => $s->can_adjust ?? [],
            ])->values(),
        ];
    }
}
