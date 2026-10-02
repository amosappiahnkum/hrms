<?php

namespace App\Http\Controllers\Competency;

use App\Enums\Competency\DevelopmentMethod;
use App\Enums\Competency\DevelopmentStatus;
use App\Exceptions\UserFacingException;
use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Models\Competency\Competency;
use App\Models\Competency\CompetencyAssessment;
use App\Models\Competency\DevelopmentAction;
use App\Models\SelfService\Employee;
use App\Models\TrainingPlan\TrainingPlanItem;
use App\Services\Competency\CompetencyService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Actions that close a competency gap. A training action can be linked to the employee's line in a
 * training plan; its progress then shows here.
 */
class DevelopmentActionController extends Controller
{
    public function __construct(private readonly CompetencyService $service)
    {
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validated($request);
        $employee = Employee::where('uuid', $request->employee_uuid)->firstOrFail();
        abort_unless($this->service->canAssess($request->user(), $employee), 403, 'You cannot plan development for this employee.');

        $competency = Competency::where('uuid', $request->competency_uuid)->firstOrFail();
        $latest = $this->service->latestAssessments([$employee->id])->get($employee->id);

        $action = DevelopmentAction::create($data + [
            'employee_id'              => $employee->id,
            'competency_id'            => $competency->id,
            'competency_assessment_id' => $latest?->id,
            'status'                   => $data['status'] ?? DevelopmentStatus::PLANNED->value,
            'created_by'               => $request->user()->id,
        ]);

        return ApiResponse::success($this->service->action($action), 'Development action added.', 201);
    }

    public function update(Request $request, DevelopmentAction $developmentAction): JsonResponse
    {
        abort_unless($this->service->canAssess($request->user(), $developmentAction->employee), 403);
        $data = $this->validated($request, $developmentAction);

        if (array_key_exists('training_plan_item_uuid', $data)) {
            $data['training_plan_item_id'] = $this->trainingFor($data['training_plan_item_uuid'], $developmentAction->employee_id);
        }
        unset($data['training_plan_item_uuid']);

        if (($data['status'] ?? null) === DevelopmentStatus::DONE->value) {
            $data['completed_on'] ??= $developmentAction->completed_on ?? now()->toDateString();
        } elseif (isset($data['status'])) {
            $data['completed_on'] = null;
        }

        $developmentAction->update($data);

        return ApiResponse::success($this->service->action($developmentAction->fresh()), 'Development action updated.');
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
            'status'                  => ['sometimes', Rule::enum(DevelopmentStatus::class)],
            'description'             => ['nullable', 'string', 'max:2000'],
            'due_on'                  => ['nullable', 'date'],
            'completed_on'            => ['nullable', 'date'],
            'outcome'                 => ['nullable', 'string', 'max:2000'],
            'training_plan_item_uuid' => [$action ? 'nullable' : 'prohibited', 'string'],
        ]);
    }

    /** A training plan line can only be linked to an action for the same employee. */
    private function trainingFor(?string $uuid, int $employeeId): ?int
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

        return $item->id;
    }
}
