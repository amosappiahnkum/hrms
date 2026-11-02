<?php

namespace App\Http\Controllers\TrainingPlan;

use App\Enums\TrainingPlan\TrainingNature;
use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Resources\TrainingPlan\TrainingCatalogueItemResource;
use App\Models\Competency\Competency;
use App\Models\TrainingPlan\TrainingCatalogueCompetency;
use App\Models\TrainingPlan\TrainingCatalogueItem;
use App\Models\TrainingPlan\TrainingDomain;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class TrainingCatalogueController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $items = TrainingCatalogueItem::query()
            ->with(['domain', 'competencyLinks.competency'])
            ->withCount('planItems')
            ->when($request->search, fn ($q, $v) => $q->where('title', 'like', "%{$v}%"))
            ->when($request->competency_uuid, fn ($q, $v) => $q->whereHas('competencyLinks.competency', fn ($c) => $c->where('uuid', $v)))
            ->when($request->nature, fn ($q, $v) => $q->where('nature', $v))
            ->when($request->domain_uuid, fn ($q, $v) => $q->whereHas('domain', fn ($d) => $d->where('uuid', $v)))
            ->orderBy('title')
            ->paginate($request->integer('per_page', 50));

        return TrainingCatalogueItemResource::collection($items);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validated($request);
        $item = DB::transaction(function () use ($data) {
            $item = TrainingCatalogueItem::create(collect($data)->except('competencies')->all());
            $this->syncCompetencies($item, $data['competencies'] ?? []);

            return $item;
        });

        return ApiResponse::success(TrainingCatalogueItemResource::make($item->load(['domain', 'competencyLinks.competency'])), 'Training added to the catalogue.', 201);
    }

    public function update(Request $request, TrainingCatalogueItem $trainingCatalogueItem): JsonResponse
    {
        $data = $this->validated($request, $trainingCatalogueItem);
        DB::transaction(function () use ($trainingCatalogueItem, $data) {
            $trainingCatalogueItem->update(collect($data)->except('competencies')->all());
            // Left out: the links stay as they are.
            if (array_key_exists('competencies', $data)) {
                $this->syncCompetencies($trainingCatalogueItem, $data['competencies']);
            }
        });

        return ApiResponse::success(TrainingCatalogueItemResource::make($trainingCatalogueItem->load(['domain', 'competencyLinks.competency'])), 'Catalogue item updated.');
    }

    public function destroy(TrainingCatalogueItem $trainingCatalogueItem): JsonResponse
    {
        // Planned items keep their own copy of the details, so removing the catalogue entry is safe.
        $trainingCatalogueItem->delete();

        return ApiResponse::success(null, 'Catalogue item removed.');
    }

    private function validated(Request $request, ?TrainingCatalogueItem $item = null): array
    {
        $required = $item ? 'sometimes' : 'required';

        $data = $request->validate([
            'title'          => [$required, 'string', 'max:255',
                Rule::unique('training_catalogue_items', 'title')->ignore($item?->id)->whereNull('deleted_at')],
            'nature'         => [$required, Rule::enum(TrainingNature::class)],
            'domain_uuid'    => ['nullable', 'string', Rule::exists('training_domains', 'uuid')->whereNull('deleted_at')],
            'default_days'   => ['nullable', 'numeric', 'min:0', 'max:365'],
            'estimated_cost' => ['nullable', 'numeric', 'min:0'],
            'trainer'        => ['nullable', 'string', 'max:255'],
            'location'       => ['nullable', 'string', 'max:255'],
            // The competencies this training develops, and the level it brings a trainee to.
            'competencies'                   => ['sometimes', 'array'],
            'competencies.*.competency_uuid' => ['required', 'distinct', Rule::exists('competencies', 'uuid')->whereNull('deleted_at')],
            'competencies.*.target_level'    => ['required', 'integer', 'between:1,4'],
        ]);

        return TrainingDomain::resolveUuid($data);
    }

    /** Make the item's links exactly these, reviving removed ones rather than duplicating them. */
    private function syncCompetencies(TrainingCatalogueItem $item, array $rows): void
    {
        $ids = Competency::whereIn('uuid', collect($rows)->pluck('competency_uuid'))->pluck('id', 'uuid');
        $keep = [];

        foreach ($rows as $row) {
            $link = TrainingCatalogueCompetency::withTrashed()->firstOrNew([
                'training_catalogue_item_id' => $item->id,
                'competency_id'              => $ids[$row['competency_uuid']],
            ]);
            $link->target_level = $row['target_level'];
            $link->deleted_at = null;
            $link->save();
            $keep[] = $link->id;
        }

        $item->competencyLinks()->whereNotIn('id', $keep)->delete();
    }
}
