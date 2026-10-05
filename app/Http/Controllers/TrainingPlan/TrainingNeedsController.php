<?php

namespace App\Http\Controllers\TrainingPlan;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Models\Competency\DevelopmentAction;
use App\Models\TrainingPlan\TrainingCatalogueCompetency;
use App\Services\Competency\CompetencyService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;

/**
 * The hand-over from the competency matrix: gaps an assessor decided to close with training or a
 * certification, not yet in a training plan. Grouped so the training team can plan them in bulk.
 */
class TrainingNeedsController extends Controller
{
    /** More than this at once is a sign the filters need narrowing. */
    private const LIMIT = 1000;

    public function __construct(private readonly CompetencyService $competency)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'group_by'            => ['nullable', Rule::in(['competency', 'course', 'department'])],
            'department_uuid'     => ['nullable', 'string'],
            'competency_uuid'     => ['nullable', 'string'],
            'catalogue_item_uuid' => ['nullable', 'string'],
            'search'              => ['nullable', 'string', 'max:100'],
        ]);

        if (!feature('competency.enabled')) {
            return ApiResponse::success(['groups' => [], 'total' => 0, 'truncated' => false]);
        }

        $actions = DevelopmentAction::awaitingTraining()
            ->with(['employee.department', 'employee.jobDetail.position', 'competency'])
            ->when($request->department_uuid, fn ($q, $v) => $q->whereHas('employee.department', fn ($d) => $d->where('uuid', $v)))
            ->when($request->competency_uuid, fn ($q, $v) => $q->whereHas('competency', fn ($c) => $c->where('uuid', $v)))
            ->when($request->catalogue_item_uuid, fn ($q, $v) => $q->whereHas('competency.courseLinks.catalogueItem', fn ($c) => $c->where('uuid', $v)))
            ->when($request->search, fn ($q, $v) => $q->whereHas('employee', fn ($e) => $e
                ->where(fn ($w) => $w->where('first_name', 'like', "%{$v}%")->orWhere('last_name', 'like', "%{$v}%")->orWhere('staff_id', 'like', "%{$v}%"))))
            ->orderBy('due_on')->orderBy('id')
            ->limit(self::LIMIT + 1)
            ->get();

        $truncated = $actions->count() > self::LIMIT;
        $actions = $actions->take(self::LIMIT);

        $courses = $this->bestCourses($actions->pluck('competency_id')->unique());
        $ratings = $this->competency->latestAssessments($actions->pluck('employee_id')->unique())
            ->map(fn ($a) => $a->ratings->keyBy('competency_id'));

        $needs = $actions->map(function (DevelopmentAction $a) use ($courses, $ratings) {
            $rating = $ratings->get($a->employee_id)?->get($a->competency_id);
            $employee = $a->employee;
            $course = $courses->get($a->competency_id);

            return [
                'uuid'        => $a->uuid,
                'employee'    => [
                    'uuid'       => $employee->uuid,
                    'name'       => trim(preg_replace('/\s+/', ' ', $employee->name)),
                    'staff_id'   => $employee->staff_id,
                    'department' => $employee->department ? ['uuid' => $employee->department->uuid, 'name' => $employee->department->name] : null,
                    'position'   => $employee->jobDetail?->position?->name,
                ],
                'competency'     => ['uuid' => $a->competency->uuid, 'name' => $a->competency->name, 'group' => CompetencyService::option($a->competency->group)],
                'required_level' => $rating?->required_level,
                'level'          => $rating?->level,
                'method'         => CompetencyService::option($a->method),
                'description'    => $a->description,
                'due_on'         => $a->due_on?->toDateString(),
                'course'         => $course,
            ];
        });

        return ApiResponse::success([
            'groups'    => $this->group($needs, $request->input('group_by', 'competency')),
            'total'     => $needs->count(),
            'truncated' => $truncated,
        ]);
    }

    /** For each competency, the catalogue training reaching the highest level (then by title). */
    private function bestCourses(Collection $competencyIds): Collection
    {
        return TrainingCatalogueCompetency::whereIn('competency_id', $competencyIds)
            ->whereHas('catalogueItem')
            ->with('catalogueItem')
            ->get()
            ->sortBy([fn ($a, $b) => $b->target_level <=> $a->target_level, fn ($a, $b) => strcasecmp($a->catalogueItem->title, $b->catalogueItem->title)])
            ->unique('competency_id')
            ->mapWithKeys(fn ($link) => [$link->competency_id => [
                'uuid'         => $link->catalogueItem->uuid,
                'title'        => $link->catalogueItem->title,
                'target_level' => $link->target_level,
            ]]);
    }

    private function group(Collection $needs, string $by): array
    {
        [$key, $label, $sub] = match ($by) {
            'course' => [
                fn ($n) => $n['course']['uuid'] ?? 'none',
                fn ($n) => $n['course']['title'] ?? 'No catalogue training linked',
                fn ($n) => null,
            ],
            'department' => [
                fn ($n) => $n['employee']['department']['uuid'] ?? 'none',
                fn ($n) => $n['employee']['department']['name'] ?? 'No department',
                fn ($n) => null,
            ],
            default => [
                fn ($n) => $n['competency']['uuid'],
                fn ($n) => $n['competency']['name'],
                fn ($n) => $n['competency']['group']['label'] ?? null,
            ],
        };

        return $needs->groupBy($key)->map(function (Collection $items, $groupKey) use ($label, $sub, $by) {
            $first = $items->first();
            // The training to start from when the whole group is planned together.
            $course = match ($by) {
                'course'     => $first['course'],
                'competency' => $first['course'],
                default      => null,
            };

            return [
                'key'      => (string) $groupKey,
                'label'    => $label($first),
                'sub'      => $sub($first),
                'course'   => $course,
                'count'    => $items->count(),
                'needs'    => $items->values(),
            ];
        })
            // Biggest groups first; the catch-all "none" group last.
            ->sortBy([fn ($a, $b) => ($a['key'] === 'none') <=> ($b['key'] === 'none'), fn ($a, $b) => $b['count'] <=> $a['count'], fn ($a, $b) => strcasecmp($a['label'], $b['label'])])
            ->values()->all();
    }
}
