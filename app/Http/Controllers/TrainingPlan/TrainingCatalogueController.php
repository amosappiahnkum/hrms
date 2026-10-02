<?php

namespace App\Http\Controllers\TrainingPlan;

use App\Enums\TrainingPlan\TrainingNature;
use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Resources\TrainingPlan\TrainingCatalogueItemResource;
use App\Models\TrainingPlan\TrainingCatalogueItem;
use App\Models\TrainingPlan\TrainingDomain;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

class TrainingCatalogueController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $items = TrainingCatalogueItem::query()
            ->with('domain')
            ->withCount('planItems')
            ->when($request->search, fn ($q, $v) => $q->where('title', 'like', "%{$v}%"))
            ->when($request->nature, fn ($q, $v) => $q->where('nature', $v))
            ->when($request->domain_uuid, fn ($q, $v) => $q->whereHas('domain', fn ($d) => $d->where('uuid', $v)))
            ->orderBy('title')
            ->paginate($request->integer('per_page', 50));

        return TrainingCatalogueItemResource::collection($items);
    }

    public function store(Request $request): JsonResponse
    {
        $item = TrainingCatalogueItem::create($this->validated($request));

        return ApiResponse::success(TrainingCatalogueItemResource::make($item->load('domain')), 'Training added to the catalogue.', 201);
    }

    public function update(Request $request, TrainingCatalogueItem $trainingCatalogueItem): JsonResponse
    {
        $trainingCatalogueItem->update($this->validated($request, $trainingCatalogueItem));

        return ApiResponse::success(TrainingCatalogueItemResource::make($trainingCatalogueItem->load('domain')), 'Catalogue item updated.');
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
        ]);

        return TrainingDomain::resolveUuid($data);
    }
}
