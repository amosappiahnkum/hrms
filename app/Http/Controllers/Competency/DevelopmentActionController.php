<?php

namespace App\Http\Controllers\Competency;

use App\Enums\Competency\DevelopmentMethod;
use App\Enums\Competency\DevelopmentStatus;
use App\Enums\Competency\EffectivenessResult;
use App\Exceptions\UserFacingException;
use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Models\Competency\Competency;
use App\Models\Competency\CompetencyAssessment;
use App\Models\Competency\DevelopmentAction;
use App\Models\SelfService\Employee;
use App\Models\TrainingPlan\TrainingPlanItem;
use App\Services\Competency\CompetencyService;
use App\Services\Competency\EffectivenessService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Actions that close a competency gap. A training action can be linked to the employee's line in a
 * training plan, when it is added or later; its progress then shows here.
 */
class DevelopmentActionController extends Controller
{
    public function __construct(
        private readonly CompetencyService $service,
        private readonly EffectivenessService $effectiveness,
    ) {
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validated($request);
        $employee = Employee::where('uuid', $request->employee_uuid)->firstOrFail();
        abort_unless($this->service->canAssess($request->user(), $employee), 403, 'You cannot plan development for this employee.');

        $competency = Competency::where('uuid', $request->competency_uuid)->firstOrFail();
        $latest = $this->service->latestAssessments([$employee->id])->get($employee->id);
        $training = $this->trainingFor($data['training_plan_item_uuid'] ?? null, $employee->id);
        unset($data['training_plan_item_uuid']);

        $action = DevelopmentAction::create($data + [
            'employee_id'              => $employee->id,
            'competency_id'            => $competency->id,
            'competency_assessment_id' => $latest?->id,
            'training_plan_item_id'    => $training?->id,
            'status'                   => $data['status'] ?? DevelopmentStatus::PLANNED->value,
            'created_by'               => $request->user()->id,
        ]);
        $action->recordTrainingSource();

        return ApiResponse::success($this->service->action($action), 'Development action added.', 201);
    }

    public function update(Request $request, DevelopmentAction $developmentAction): JsonResponse
    {
        abort_unless($this->service->canAssess($request->user(), $developmentAction->employee), 403);
        $data = $this->validated($request, $developmentAction);

        $linking = array_key_exists('training_plan_item_uuid', $data);
        if ($linking) {
            $data['training_plan_item_id'] = $this->trainingFor($data['training_plan_item_uuid'], $developmentAction->employee_id)?->id;
        }
        unset($data['training_plan_item_uuid']);

        if (!$developmentAction->status->isOpen()) {
            throw new UserFacingException('A finished action can\'t be changed. Add a new action for the gap instead.');
        }

        $developmentAction->update($data);
        if ($linking) {
            $developmentAction->recordTrainingSource();
        }

        return ApiResponse::success($this->service->action($developmentAction->fresh()), 'Development action updated.');
    }

    /**
     * The effectiveness check that finishes an action (SOP 5.3.6): re-rate the competency and say how
     * effective the action was. Only this closes a gap.
     */
    public function evaluate(Request $request, DevelopmentAction $developmentAction): JsonResponse
    {
        abort_unless($this->service->canAssess($request->user(), $developmentAction->employee), 403, 'You cannot assess this employee.');

        $data = $request->validate([
            'result'         => ['required', Rule::enum(EffectivenessResult::class)],
            'verified_level' => ['required', 'integer', 'between:0,4'],
            'evidence'       => ['required', 'string', 'max:2000'],
            'outcome'        => ['nullable', 'string', 'max:2000'],
        ], ['evidence.required' => 'Record how competence was verified (the method\'s effectiveness check).']);

        $action = $this->effectiveness->evaluate($developmentAction, $request->user(), $data);

        return ApiResponse::success($this->service->action($action), $action->verifiedRating?->gap() === 0
            ? 'Evaluated: the gap is closed.'
            : 'Evaluated. The gap is still open; plan another action for it.');
    }

    public function destroy(Request $request, DevelopmentAction $developmentAction): JsonResponse
    {
        abort_unless($this->service->canAssess($request->user(), $developmentAction->employee), 403);
        $developmentAction->delete();

        return ApiResponse::success(null, 'Development action removed.');
    }

    private function validated(Request $request, ?DevelopmentAction $action = null): array
    {
        return $request->validate([
            'employee_uuid'           => [$action ? 'prohibited' : 'required', Rule::exists('employees', 'uuid')->whereNull('deleted_at')],
            'competency_uuid'         => [$action ? 'prohibited' : 'required', Rule::exists('competencies', 'uuid')->whereNull('deleted_at')],
            'method'                  => [$action ? 'sometimes' : 'required', Rule::enum(DevelopmentMethod::class)],
            // Done comes only from the effectiveness check, awaiting evaluation from the linked training.
            'status'                  => ['sometimes', Rule::in([DevelopmentStatus::PLANNED->value, DevelopmentStatus::IN_PROGRESS->value, DevelopmentStatus::CANCELLED->value])],
            'description'             => ['nullable', 'string', 'max:2000'],
            'due_on'                  => ['nullable', 'date'],
            'outcome'                 => ['nullable', 'string', 'max:2000'],
            'training_plan_item_uuid' => ['nullable', 'string'],
        ]);
    }

    /** A training plan line can only be linked to an action for the same employee. */
    private function trainingFor(?string $uuid, int $employeeId): ?TrainingPlanItem
    {
        if (!$uuid) {
            return null;
        }

        if (!feature('training_plan.enabled')) {
            throw new UserFacingException('Training plans are switched off, so this action can\'t be linked to a planned training.');
        }

        $item = TrainingPlanItem::where('uuid', $uuid)->first();
        if (!$item || $item->employee_id !== $employeeId) {
            throw new UserFacingException('That training is not planned for this employee.');
        }

        return $item;
    }
}
