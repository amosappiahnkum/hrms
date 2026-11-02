<?php

namespace App\Http\Controllers\Competency;

use App\Enums\Competency\CompetencyGroup;
use App\Enums\Competency\DevelopmentMethod;
use App\Enums\Competency\DevelopmentStatus;
use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Models\Competency\Competency;
use App\Services\Competency\CompetencyService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** The competency library. */
class CompetencyController extends Controller
{
    /** Groups, levels and development methods, for forms and legends. */
    public function options(): JsonResponse
    {
        $cases = fn (array $cases) => collect($cases)->map(fn ($c) => CompetencyService::option($c))->values();

        return ApiResponse::success([
            'groups'   => $cases(CompetencyGroup::cases()),
            'levels'   => CompetencyService::levels(),
            'methods'  => collect(DevelopmentMethod::cases())->map(fn ($m) => CompetencyService::option($m) + ['effectiveness_check' => $m->effectivenessCheck()])->values(),
            'statuses' => $cases(DevelopmentStatus::cases()),
        ]);
    }

    /**
     * Catalogue trainings that develop this competency, those reaching the highest level first.
     * Empty while training plans are switched off, since nothing could be planned from them.
     */
    public function courses(Competency $competency): JsonResponse
    {
        if (!feature('training_plan.enabled')) {
            return ApiResponse::success([]);
        }

        $courses = $competency->courseLinks()
            ->whereHas('catalogueItem')
            ->with('catalogueItem.domain')
            ->get()
            ->sortBy([fn ($a, $b) => $b->target_level <=> $a->target_level, fn ($a, $b) => strcasecmp($a->catalogueItem->title, $b->catalogueItem->title)])
            ->map(fn ($link) => [
                'uuid'           => $link->catalogueItem->uuid,
                'title'          => $link->catalogueItem->title,
                'nature'         => CompetencyService::option($link->catalogueItem->nature),
                'domain'         => $link->catalogueItem->domain?->name,
                'default_days'   => $link->catalogueItem->default_days,
                'estimated_cost' => $link->catalogueItem->estimated_cost,
                'trainer'        => $link->catalogueItem->trainer,
                'target_level'   => $link->target_level,
            ]);

        return ApiResponse::success($courses->values());
    }

    public function index(Request $request): JsonResponse
    {
        $competencies = Competency::query()
            ->withCount(['requirements as positions_count' => fn ($q) => $q->whereHas('position')])
            ->when($request->search, fn ($q, $v) => $q->where('name', 'like', "%{$v}%"))
            ->when($request->group, fn ($q, $v) => $q->where('group', $v))
            ->orderBy('group')->orderBy('name')
            ->paginate(min($request->integer('per_page', 25), 500));

        return ApiResponse::success([
            'data' => collect($competencies->items())->map(fn (Competency $c) => $this->row($c))->values(),
            'meta' => ['current_page' => $competencies->currentPage(), 'per_page' => $competencies->perPage(), 'total' => $competencies->total(), 'last_page' => $competencies->lastPage()],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $competency = Competency::create($this->validated($request));

        return ApiResponse::success($this->row($competency->loadCount('requirements as positions_count')), 'Competency added.', 201);
    }

    public function update(Request $request, Competency $competency): JsonResponse
    {
        $competency->update($this->validated($request, $competency));

        return ApiResponse::success($this->row($competency->loadCount('requirements as positions_count')), 'Competency updated.');
    }

    /** Positions stop requiring it; past assessments keep their ratings. */
    public function destroy(Competency $competency): JsonResponse
    {
        $competency->requirements()->delete();
        $competency->delete();

        return ApiResponse::success(null, 'Competency removed.');
    }

    private function validated(Request $request, ?Competency $competency = null): array
    {
        return $request->validate([
            'name'        => [
                $competency ? 'sometimes' : 'required', 'string', 'max:255',
                Rule::unique('competencies')->where('group', $request->input('group', $competency?->group?->value))
                    ->whereNull('deleted_at')->ignore($competency?->id),
            ],
            'group'       => [$competency ? 'sometimes' : 'required', Rule::enum(CompetencyGroup::class)],
            'description' => ['nullable', 'string', 'max:2000'],
        ], ['name.unique' => 'This group already has a competency with that name.']);
    }

    private function row(Competency $c): array
    {
        return [
            'uuid'            => $c->uuid,
            'name'            => $c->name,
            'group'           => CompetencyService::option($c->group),
            'description'     => $c->description,
            'positions_count' => (int) ($c->positions_count ?? 0),
        ];
    }
}
