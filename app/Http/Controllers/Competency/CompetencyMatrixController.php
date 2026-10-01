<?php

namespace App\Http\Controllers\Competency;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Models\Competency\Competency;
use App\Models\Competency\CompetencyAssessment;
use App\Models\Competency\CompetencyRating;
use App\Models\Competency\DevelopmentAction;
use App\Models\Competency\PositionCompetency;
use App\Models\JobDetail;
use App\Models\SelfService\Employee;
use App\Services\Competency\CompetencyService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** The matrix (employees × competencies), the list of gaps, and their headline numbers. */
class CompetencyMatrixController extends Controller
{
    public function __construct(private readonly CompetencyService $service)
    {
    }

    /**
     * One page of employees, with a column for every competency their positions require. Each cell
     * has the required level (r) and the employee's current level (l).
     */
    public function matrix(Request $request): JsonResponse
    {
        $filters = $request->only(['department_uuid', 'position_uuid', 'search']);
        $employees = $this->service->employees($request->user(), $filters);

        // Columns: every competency required by the positions of the filtered employees.
        $positionIds = JobDetail::whereIn('employee_id', (clone $employees)->select('id'))->whereNotNull('position_id')->distinct()->pluck('position_id');
        $requirements = PositionCompetency::whereIn('position_id', $positionIds)->whereHas('competency')->get(['position_id', 'competency_id', 'required_level']);
        $columns = Competency::whereIn('id', $requirements->pluck('competency_id')->unique())->get()
            ->sortBy([fn ($c) => $c->group->value, fn ($c) => $c->name])->values();

        $page = $employees->with(['jobDetail.position', 'department'])->orderBy('first_name')->orderBy('last_name')
            ->paginate($request->integer('per_page', 25));
        $latest = $this->service->latestAssessments(collect($page->items())->pluck('id'));
        $byPosition = $requirements->groupBy('position_id');

        $rows = collect($page->items())->map(function (Employee $e) use ($latest, $byPosition, $columns) {
            $assessment = $latest->get($e->id);
            $levels = $assessment?->ratings->pluck('level', 'competency_id') ?? collect();
            $required = ($byPosition->get($e->jobDetail?->position_id) ?? collect())->pluck('required_level', 'competency_id');

            $cells = [];
            $gaps = 0;
            foreach ($columns as $c) {
                $r = $required->get($c->id);
                $l = $levels->get($c->id);
                if ($r === null && $l === null) {
                    continue;
                }
                $cells[$c->uuid] = ['r' => $r, 'l' => $l];
                if ($r !== null && $l !== null && $l < $r) {
                    $gaps++;
                }
            }

            return [
                'employee'    => ['uuid' => $e->uuid, 'name' => trim(preg_replace('/\s+/', ' ', $e->name)), 'staff_id' => $e->staff_id, 'department' => $e->department?->name],
                'position'    => $e->jobDetail?->position?->name,
                'assessed_on' => $assessment?->assessed_on?->toDateString(),
                'review_due'  => (bool) $assessment?->next_review_on?->isPast(),
                'gaps'        => $gaps,
                'cells'       => $cells,
            ];
        });

        return ApiResponse::success([
            'columns' => $columns->map(fn (Competency $c) => ['uuid' => $c->uuid, 'name' => $c->name, 'group' => CompetencyService::option($c->group)])->values(),
            'data'    => $rows->values(),
            'meta'    => ['current_page' => $page->currentPage(), 'per_page' => $page->perPage(), 'total' => $page->total(), 'last_page' => $page->lastPage()],
        ]);
    }

    /** Every competency where an employee's current level is below what their role required. */
    public function gaps(Request $request): JsonResponse
    {
        $gaps = $this->gapQuery($request)
            ->with(['competency', 'assessment.employee.department', 'assessment.position'])
            ->orderByRaw('(required_level - level) desc')->orderBy('id')
            ->paginate($request->integer('per_page', 25));

        $items = collect($gaps->items());
        $actions = DevelopmentAction::open()
            ->whereIn('employee_id', $items->map(fn ($g) => $g->assessment->employee_id))
            ->whereIn('competency_id', $items->pluck('competency_id'))
            ->get()->groupBy(fn ($a) => "{$a->employee_id}:{$a->competency_id}");

        $rows = $items->map(function (CompetencyRating $g) use ($actions) {
            $employee = $g->assessment->employee;
            $open = $actions->get("{$employee->id}:{$g->competency_id}", collect());

            return [
                'uuid'           => $g->uuid,
                'employee'       => ['uuid' => $employee->uuid, 'name' => trim(preg_replace('/\s+/', ' ', $employee->name)), 'staff_id' => $employee->staff_id, 'department' => $employee->department?->name],
                'position'       => $g->assessment->position?->name,
                'competency'     => ['uuid' => $g->competency->uuid, 'name' => $g->competency->name, 'group' => CompetencyService::option($g->competency->group)],
                'required_level' => $g->required_level,
                'level'          => $g->level,
                'gap'            => $g->gap(),
                'assessed_on'    => $g->assessment->assessed_on?->toDateString(),
                'actions'        => $open->map(fn ($a) => ['uuid' => $a->uuid, 'method' => CompetencyService::option($a->method), 'status' => CompetencyService::option($a->status)])->values(),
            ];
        });

        return ApiResponse::success([
            'data' => $rows->values(),
            'meta' => ['current_page' => $gaps->currentPage(), 'per_page' => $gaps->perPage(), 'total' => $gaps->total(), 'last_page' => $gaps->lastPage()],
        ]);
    }

    /** Headline numbers for the employees in view. */
    public function summary(Request $request): JsonResponse
    {
        $employees = $this->service->employees($request->user(), $request->only(['department_uuid', 'position_uuid']));
        $ids = (clone $employees)->pluck('id');
        $latest = $this->service->latestAssessments($ids);

        $gapPairs = $this->gapQuery($request)->with('assessment:id,employee_id')->get(['id', 'competency_assessment_id', 'competency_id'])
            ->map(fn ($g) => "{$g->assessment->employee_id}:{$g->competency_id}");
        $planned = DevelopmentAction::open()->whereIn('employee_id', $ids)->get(['employee_id', 'competency_id'])
            ->map(fn ($a) => "{$a->employee_id}:{$a->competency_id}")->unique();

        return ApiResponse::success([
            'employees'      => $ids->count(),
            'assessed'       => $latest->count(),
            'reviews_due'    => $latest->filter(fn ($a) => $a->next_review_on?->isPast())->count(),
            'drafts'         => CompetencyAssessment::where('status', CompetencyAssessment::DRAFT)->whereIn('employee_id', $ids)->count(),
            'gaps'           => $gapPairs->count(),
            'gaps_unplanned' => $gapPairs->diff($planned)->count(),
        ]);
    }

    /** Current gaps (latest assessment, level below required) for the employees in view. */
    private function gapQuery(Request $request)
    {
        $visible = $this->service->employees($request->user(), $request->only(['department_uuid', 'position_uuid', 'search']))->select('id');

        return $this->service->latestRatings()
            ->whereNotNull('level')->whereNotNull('required_level')
            ->whereColumn('level', '<', 'required_level')
            ->whereHas('assessment', fn ($a) => $a->whereIn('employee_id', $visible))
            ->whereHas('competency')
            ->when($request->competency_uuid, fn ($q, $v) => $q->whereHas('competency', fn ($c) => $c->where('uuid', $v)))
            ->when($request->group, fn ($q, $v) => $q->whereHas('competency', fn ($c) => $c->where('group', $v)));
    }
}
