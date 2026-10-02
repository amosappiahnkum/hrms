<?php

namespace App\Http\Controllers\TrainingPlan;

use App\Exceptions\UserFacingException;
use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Models\TrainingPlan\TrainingPlanApprovalLevel;
use App\Models\User;
use App\Services\TrainingPlan\TrainingPlanAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Who validates and who finally approves training plans: ordered levels of named people. Plans
 * already submitted keep the levels they were submitted with; changes apply from the next submission.
 *
 * The people in the levels hold `review-training-plan` (so they can open plans); this page keeps
 * that permission in step with the levels (see TrainingPlanAccess::syncReviewers), so it is not
 * granted by hand.
 */
class TrainingPlanApprovalLevelController extends Controller
{
    private const MAX_LEVELS = 10;

    public function __construct(private readonly TrainingPlanAccess $access) {}

    public function index(): JsonResponse
    {
        return ApiResponse::success($this->levels());
    }

    /** Users who can be put in a level, by name, email or staff ID. */
    public function candidates(Request $request): JsonResponse
    {
        $request->validate(['search' => ['nullable', 'string', 'max:100']]);
        $term = trim((string) $request->search);

        $users = User::query()
            ->with('employee.department')
            ->when($term !== '', fn ($q) => $q->where(fn ($w) => $w
                ->where('name', 'like', "%{$term}%")
                ->orWhere('email', 'like', "%{$term}%")
                ->orWhereHas('employee', fn ($e) => $e->search($term))))
            ->orderBy('name')
            ->limit(20)
            ->get();

        return ApiResponse::success($users->map(fn (User $u) => $this->person($u))->values());
    }

    public function store(Request $request): JsonResponse
    {
        if (TrainingPlanApprovalLevel::count() >= self::MAX_LEVELS) {
            throw new UserFacingException('A plan can have at most ' . self::MAX_LEVELS . ' approval levels.');
        }

        $data = $this->validated($request);

        DB::transaction(function () use ($data) {
            $level = TrainingPlanApprovalLevel::create([
                'name'     => $data['name'],
                'rule'     => $data['rule'],
                'position' => (int) TrainingPlanApprovalLevel::max('position') + 1,
            ]);
            $level->users()->sync($this->userIds($data['user_uuids']));
            $this->access->syncReviewers();
        });

        return ApiResponse::success($this->levels(), 'Approval level added.', 201);
    }

    public function update(Request $request, TrainingPlanApprovalLevel $trainingPlanApprovalLevel): JsonResponse
    {
        $data = $this->validated($request);

        DB::transaction(function () use ($data, $trainingPlanApprovalLevel) {
            $trainingPlanApprovalLevel->update(['name' => $data['name'], 'rule' => $data['rule']]);
            $trainingPlanApprovalLevel->users()->sync($this->userIds($data['user_uuids']));
            $this->access->syncReviewers();
        });

        return ApiResponse::success($this->levels(), 'Approval level updated.');
    }

    /** Levels in their new order: every level's uuid, first to last. */
    public function reorder(Request $request): JsonResponse
    {
        $request->validate([
            'uuids'   => ['required', 'array'],
            'uuids.*' => ['distinct', 'string', Rule::exists('training_plan_approval_levels', 'uuid')->whereNull('deleted_at')],
        ]);

        if (count($request->uuids) !== TrainingPlanApprovalLevel::count()) {
            throw new UserFacingException('List every approval level in its new order.');
        }

        DB::transaction(function () use ($request) {
            foreach ($request->uuids as $position => $uuid) {
                TrainingPlanApprovalLevel::where('uuid', $uuid)->update(['position' => $position + 1]);
            }
        });

        return ApiResponse::success($this->levels(), 'Approval order updated.');
    }

    public function destroy(TrainingPlanApprovalLevel $trainingPlanApprovalLevel): JsonResponse
    {
        DB::transaction(function () use ($trainingPlanApprovalLevel) {
            $trainingPlanApprovalLevel->users()->detach();
            $trainingPlanApprovalLevel->delete();
            $this->access->syncReviewers();
        });

        return ApiResponse::success($this->levels(), 'Approval level removed.');
    }

    private function validated(Request $request): array
    {
        return $request->validate([
            'name'         => ['required', 'string', 'max:100'],
            'rule'         => ['required', Rule::in(TrainingPlanApprovalLevel::RULES)],
            'user_uuids'   => ['required', 'array', 'min:1', 'max:20'],
            'user_uuids.*' => ['distinct', 'string', Rule::exists('users', 'uuid')->whereNull('deleted_at')],
        ], [
            'user_uuids.required' => 'Add at least one person to the level.',
            'user_uuids.min'      => 'Add at least one person to the level.',
        ]);
    }

    private function userIds(array $uuids): array
    {
        return User::whereIn('uuid', $uuids)->pluck('id')->all();
    }

    private function levels(): array
    {
        $levels = TrainingPlanApprovalLevel::with('users.employee.department')->orderBy('position')->orderBy('id')->get();
        $last = $levels->count() - 1;

        return $levels->values()->map(fn (TrainingPlanApprovalLevel $level, int $i) => [
            'uuid'   => $level->uuid,
            'name'   => $level->name,
            'rule'   => $level->rule,
            'final'  => $i === $last,
            'people' => $level->users->sortBy('name')->map(fn (User $u) => $this->person($u))->values(),
        ])->all();
    }

    private function person(User $user): array
    {
        return [
            'uuid'       => $user->uuid,
            'name'       => $user->employee?->name ?? $user->name,
            'department' => $user->employee?->department?->name,
        ];
    }
}
