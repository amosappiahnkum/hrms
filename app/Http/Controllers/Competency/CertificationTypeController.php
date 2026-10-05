<?php

namespace App\Http\Controllers\Competency;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Models\Competency\CertificationType;
use App\Models\Competency\Competency;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** The kinds of certificate roles can require. Managed by HR (competency or certification managers). */
class CertificationTypeController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $types = CertificationType::query()
            ->with('competency')
            ->withCount(['positionRequirements as positions_count' => fn ($q) => $q->whereHas('position'), 'certifications'])
            ->when($request->search, fn ($q, $v) => $q->where('name', 'like', "%{$v}%"))
            ->orderBy('name')
            ->get();

        return ApiResponse::success($types->map(fn (CertificationType $t) => $this->row($t))->values());
    }

    public function store(Request $request): JsonResponse
    {
        $type = CertificationType::create($this->validated($request));

        return ApiResponse::success($this->row($type->load('competency')->loadCount(['positionRequirements as positions_count', 'certifications'])), 'Certification type added.', 201);
    }

    public function update(Request $request, CertificationType $certificationType): JsonResponse
    {
        $certificationType->update($this->validated($request, $certificationType));

        return ApiResponse::success($this->row($certificationType->load('competency')->loadCount(['positionRequirements as positions_count', 'certifications'])), 'Certification type updated.');
    }

    /** Positions stop requiring it; certificates of this type keep their details. */
    public function destroy(CertificationType $certificationType): JsonResponse
    {
        $certificationType->delete();

        return ApiResponse::success(null, 'Certification type removed.');
    }

    private function validated(Request $request, ?CertificationType $type = null): array
    {
        $data = $request->validate([
            'name'            => [$type ? 'sometimes' : 'required', 'string', 'max:255',
                Rule::unique('certification_types', 'name')->ignore($type?->id)->whereNull('deleted_at')],
            'description'     => ['nullable', 'string', 'max:2000'],
            'validity_months' => ['nullable', 'integer', 'between:1,600'],
            'competency_uuid' => ['nullable', 'string', Rule::exists('competencies', 'uuid')->whereNull('deleted_at')],
        ]);

        if (array_key_exists('competency_uuid', $data)) {
            $data['competency_id'] = $data['competency_uuid'] ? Competency::where('uuid', $data['competency_uuid'])->value('id') : null;
            unset($data['competency_uuid']);
        }

        return $data;
    }

    private function row(CertificationType $t): array
    {
        return [
            'uuid'               => $t->uuid,
            'name'               => $t->name,
            'description'        => $t->description,
            'validity_months'    => $t->validity_months,
            'competency'         => $t->competency ? ['uuid' => $t->competency->uuid, 'name' => $t->competency->name] : null,
            'positions_count'    => (int) ($t->positions_count ?? 0),
            'certifications_count' => (int) ($t->certifications_count ?? 0),
        ];
    }
}
