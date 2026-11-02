<?php

namespace App\Http\Controllers\Competency;

use App\Enums\Competency\AuthorizationStatus;
use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Models\Competency\AuthorizationActivity;
use App\Models\Competency\EmployeeAuthorization;
use App\Models\SelfService\Employee;
use App\Models\User;
use App\Services\Competency\AuthorizationService;
use App\Services\Competency\CompetencyService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * The authorization register (SOP 5.3.3): who may perform each activity, on whose authority and
 * until when. Those who may assess an employee recommend; those who hold grant-authorizations decide.
 */
class AuthorizationController extends Controller
{
    public function __construct(
        private readonly CompetencyService $competency,
        private readonly AuthorizationService $authorizations,
    ) {
    }

    /** The register, for the employees the user may see. Current entries by default. */
    public function index(Request $request): JsonResponse
    {
        $request->validate(['status' => ['nullable', Rule::in(['current', 'all', ...array_column(AuthorizationStatus::cases(), 'value')])]]);
        $visible = $this->competency->scopeVisible(Employee::query(), $request->user())->select('id');
        $status = $request->input('status', 'current');

        $page = EmployeeAuthorization::query()
            ->whereIn('employee_id', $visible)
            ->whereHas('activity')
            ->with(['employee.department', 'activity', 'recommender', 'granter', 'changer'])
            ->when($status === 'current', fn ($q) => $q->current())
            ->when(!in_array($status, ['current', 'all'], true), fn ($q) => $q->where('status', $status))
            ->when($request->activity_uuid, fn ($q, $v) => $q->whereHas('activity', fn ($a) => $a->where('uuid', $v)))
            ->when($request->employee_uuid, fn ($q, $v) => $q->whereHas('employee', fn ($e) => $e->where('uuid', $v)))
            ->when($request->search, fn ($q, $v) => $q->whereHas('employee', fn ($e) => $e
                ->where(fn ($w) => $w->where('first_name', 'like', "%{$v}%")->orWhere('last_name', 'like', "%{$v}%")->orWhere('staff_id', 'like', "%{$v}%"))))
            ->orderByRaw("FIELD(status, 'recommended', 'suspended', 'authorized', 'expired', 'revoked', 'declined')")
            ->latest('id')
            ->paginate($request->integer('per_page', 25));

        return ApiResponse::success([
            'data' => collect($page->items())->map(fn ($a) => $this->row($a, $request->user()))->values(),
            'meta' => ['current_page' => $page->currentPage(), 'per_page' => $page->perPage(), 'total' => $page->total(), 'last_page' => $page->lastPage()],
        ]);
    }

    /** Every activity, with whether the employee is eligible and where they stand on it. */
    public function options(Request $request, Employee $employee): JsonResponse
    {
        abort_unless($this->competency->canSee($request->user(), $employee), 403);

        $current = EmployeeAuthorization::current()->where('employee_id', $employee->id)->get()->keyBy('authorization_activity_id');
        $activities = AuthorizationActivity::with(['position', 'requirements.competency', 'requirements.certificationType'])->orderBy('name')->get();

        return ApiResponse::success([
            'can_recommend' => $this->competency->canAssess($request->user(), $employee),
            'can_grant'     => $this->authorizations->canGrant($request->user()),
            'activities'    => $activities->map(fn (AuthorizationActivity $a) => AuthorizationActivityController::row($a) + [
                'unmet'   => $this->authorizations->unmet($employee, $a),
                'current' => ($c = $current->get($a->id)) ? ['uuid' => $c->uuid, 'status' => CompetencyService::option($c->status)] : null,
            ])->values(),
        ]);
    }

    /** Recommend (anyone who may assess the employee); someone who grants may authorize at once. */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'employee_uuid' => ['required', 'string', Rule::exists('employees', 'uuid')->whereNull('deleted_at')],
            'activity_uuid' => ['required', 'string', Rule::exists('authorization_activities', 'uuid')->whereNull('deleted_at')],
            'note'          => ['nullable', 'string', 'max:2000'],
            'grant'         => ['sometimes', 'boolean'],
            'valid_until'   => ['nullable', 'date', 'after:today'],
        ]);
        $employee = Employee::where('uuid', $data['employee_uuid'])->firstOrFail();
        abort_unless($this->competency->canAssess($request->user(), $employee), 403, 'You cannot recommend this employee.');
        $grant = $request->boolean('grant');
        abort_if($grant && !$this->authorizations->canGrant($request->user()), 403, 'You cannot grant authorizations.');

        $authorization = $this->authorizations->recommend(
            $employee, AuthorizationActivity::where('uuid', $data['activity_uuid'])->firstOrFail(), $request->user(),
            $data['note'] ?? null, $grant, $data['valid_until'] ?? null,
        );

        return ApiResponse::success($this->row($authorization->fresh($this->relations()), $request->user()), $grant ? 'Authorized.' : 'Recommended. Someone who grants authorizations will review it.', 201);
    }

    public function grant(Request $request, EmployeeAuthorization $employeeAuthorization): JsonResponse
    {
        $this->ensureCanGrant($request);
        $data = $request->validate(['valid_until' => ['nullable', 'date', 'after:today']]);

        $this->authorizations->grant($employeeAuthorization, $request->user(), $data['valid_until'] ?? null);

        return ApiResponse::success($this->row($employeeAuthorization->fresh($this->relations()), $request->user()), 'Authorized.');
    }

    public function decline(Request $request, EmployeeAuthorization $employeeAuthorization): JsonResponse
    {
        $this->ensureCanGrant($request);
        $reason = $request->validate(['reason' => ['required', 'string', 'max:2000']])['reason'];

        $this->authorizations->decline($employeeAuthorization, $request->user(), $reason);

        return ApiResponse::success($this->row($employeeAuthorization->fresh($this->relations()), $request->user()), 'Recommendation declined.');
    }

    public function revoke(Request $request, EmployeeAuthorization $employeeAuthorization): JsonResponse
    {
        $this->ensureCanGrant($request);
        $reason = $request->validate(['reason' => ['required', 'string', 'max:2000']])['reason'];

        $this->authorizations->revoke($employeeAuthorization, $request->user(), $reason);

        return ApiResponse::success($this->row($employeeAuthorization->fresh($this->relations()), $request->user()), 'Authorization revoked.');
    }

    private function ensureCanGrant(Request $request): void
    {
        abort_unless($this->authorizations->canGrant($request->user()), 403, 'You cannot grant authorizations.');
    }

    private function relations(): array
    {
        return ['employee.department', 'activity', 'recommender', 'granter', 'changer'];
    }

    public function row(EmployeeAuthorization $a, User $viewer): array
    {
        return [
            'uuid'                => $a->uuid,
            'employee'            => ['uuid' => $a->employee->uuid, 'name' => trim(preg_replace('/\s+/', ' ', $a->employee->name)), 'staff_id' => $a->employee->staff_id, 'department' => $a->employee->department?->name],
            'activity'            => ['uuid' => $a->activity->uuid, 'name' => $a->activity->name],
            'status'              => CompetencyService::option($a->status),
            'recommended_by'      => $a->recommender?->name,
            'recommended_at'      => $a->recommended_at?->toDateString(),
            'recommendation_note' => $a->recommendation_note,
            'authorized_by'       => $a->granter?->name,
            'authorized_at'       => $a->authorized_at?->toDateString(),
            'valid_until'         => $a->valid_until?->toDateString(),
            'reason'              => $a->reason,
            'changed_by'          => $a->changer?->name,
            'changed_at'          => $a->status_changed_at?->toDateString(),
            'can_decide'          => $this->authorizations->canGrant($viewer),
        ];
    }
}
