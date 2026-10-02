<?php

namespace App\Http\Controllers\TrainingPlan;

use App\Exceptions\UserFacingException;
use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Models\TrainingPlan\TrainingDomain;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class TrainingDomainController extends Controller
{
    /** Every domain, with how many catalogue trainings and planned trainees use it. */
    public function index(): JsonResponse
    {
        $domains = TrainingDomain::query()
            ->withCount(['catalogueItems', 'planItems'])
            ->orderBy('name')
            ->get();

        return ApiResponse::success($domains->map(fn (TrainingDomain $d) => $this->format($d))->values());
    }

    public function store(Request $request): JsonResponse
    {
        $domain = TrainingDomain::create($this->validated($request));

        return ApiResponse::success($this->format($domain), 'Domain added.', 201);
    }

    /** Renaming applies everywhere the domain is used. */
    public function update(Request $request, TrainingDomain $trainingDomain): JsonResponse
    {
        $trainingDomain->update($this->validated($request, $trainingDomain));

        return ApiResponse::success($this->format($trainingDomain->loadCount(['catalogueItems', 'planItems'])), 'Domain updated.');
    }

    public function destroy(TrainingDomain $trainingDomain): JsonResponse
    {
        if ($trainingDomain->catalogueItems()->exists() || $trainingDomain->planItems()->exists()) {
            throw new UserFacingException("\"{$trainingDomain->name}\" is used by trainings, so it can't be removed. Move them to another domain first.");
        }

        $trainingDomain->delete();

        return ApiResponse::success(null, 'Domain removed.');
    }

    private function validated(Request $request, ?TrainingDomain $domain = null): array
    {
        $request->merge(['name' => preg_replace('/\s+/', ' ', trim((string) $request->name))]);

        return $request->validate([
            'name' => ['required', 'string', 'max:100',
                Rule::unique('training_domains', 'name')->ignore($domain?->id)->whereNull('deleted_at')],
        ], ['name.unique' => 'That domain already exists.']);
    }

    private function format(TrainingDomain $domain): array
    {
        return [
            'uuid'                  => $domain->uuid,
            'name'                  => $domain->name,
            'catalogue_items_count' => $domain->catalogue_items_count ?? 0,
            'plan_items_count'      => $domain->plan_items_count ?? 0,
        ];
    }
}
