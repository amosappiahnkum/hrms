<?php

namespace App\Http\Controllers\Competency;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Models\Competency\Competency;
use App\Models\Competency\PositionCompetency;
use App\Models\Position;
use App\Services\Competency\CompetencyService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/** What each position requires: a level per competency (the matrix's "competency required" rows). */
class PositionCompetencyController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $positions = Position::query()
            ->withCount([
                'jobDetails as employees_count',
                'competencyRequirements as requirements_count' => fn ($q) => $q->whereHas('competency'),
            ])
            ->when($request->search, fn ($q, $v) => $q->where('name', 'like', "%{$v}%"))
            ->orderBy('name')
            ->paginate($request->integer('per_page', 25));

        return ApiResponse::success([
            'data' => collect($positions->items())->map(fn (Position $p) => [
                'uuid'               => $p->uuid,
                'name'               => $p->name,
                'employees_count'    => (int) $p->employees_count,
                'requirements_count' => (int) $p->requirements_count,
            ])->values(),
            'meta' => ['current_page' => $positions->currentPage(), 'per_page' => $positions->perPage(), 'total' => $positions->total(), 'last_page' => $positions->lastPage()],
        ]);
    }

    public function show(Position $position): JsonResponse
    {
        return ApiResponse::success($this->payload($position));
    }

    private function payload(Position $position): array
    {
        $requirements = $position->competencyRequirements()->whereHas('competency')->with('competency')->get()
            ->sortBy([fn ($r) => $r->competency->group->value, fn ($r) => $r->competency->name])
            ->map(fn (PositionCompetency $r) => [
                'competency'     => ['uuid' => $r->competency->uuid, 'name' => $r->competency->name, 'group' => CompetencyService::option($r->competency->group)],
                'required_level' => $r->required_level,
            ])->values();

        return ['position' => ['uuid' => $position->uuid, 'name' => $position->name], 'requirements' => $requirements];
    }

    /** Replace the position's requirements with the list given. */
    public function sync(Request $request, Position $position): JsonResponse
    {
        $data = $request->validate([
            'requirements'                  => ['present', 'array'],
            'requirements.*.competency_uuid' => ['required', 'distinct', Rule::exists('competencies', 'uuid')->whereNull('deleted_at')],
            'requirements.*.required_level'  => ['required', 'integer', 'between:1,4'],
        ]);

        $ids = Competency::whereIn('uuid', collect($data['requirements'])->pluck('competency_uuid'))->pluck('id', 'uuid');

        DB::transaction(function () use ($position, $data, $ids) {
            $keep = [];
            foreach ($data['requirements'] as $row) {
                $requirement = PositionCompetency::withTrashed()->firstOrNew([
                    'position_id'   => $position->id,
                    'competency_id' => $ids[$row['competency_uuid']],
                ]);
                $requirement->required_level = $row['required_level'];
                $requirement->deleted_at = null;
                $requirement->save();
                $keep[] = $requirement->id;
            }

            $position->competencyRequirements()->whereNotIn('id', $keep)->delete();
        });

        return ApiResponse::success($this->payload($position), 'Requirements saved.');
    }
}
