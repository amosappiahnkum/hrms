<?php

namespace App\Http\Controllers\Competency;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Models\Competency\AuthorizationActivity;
use App\Models\Competency\CertificationType;
use App\Models\Competency\Competency;
use App\Models\Position;
use App\Services\Competency\CompetencyService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/** Activities that need formal authorization, and what each requires. Set up by HR. */
class AuthorizationActivityController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $activities = AuthorizationActivity::query()
            ->with(['position', 'requirements.competency', 'requirements.certificationType'])
            ->withCount(['authorizations as authorized_count' => fn ($q) => $q->where('status', 'authorized')->whereHas('employee')])
            ->when($request->search, fn ($q, $v) => $q->where('name', 'like', "%{$v}%"))
            ->orderBy('name')
            ->get();

        return ApiResponse::success($activities->map(fn ($a) => self::row($a))->values());
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validated($request);
        $activity = DB::transaction(function () use ($data) {
            $activity = AuthorizationActivity::create(collect($data)->except('requirements')->all());
            $this->syncRequirements($activity, $data['requirements']);

            return $activity;
        });

        return ApiResponse::success(self::row($this->fresh($activity)), 'Activity added.', 201);
    }

    public function update(Request $request, AuthorizationActivity $authorizationActivity): JsonResponse
    {
        $data = $this->validated($request, $authorizationActivity);
        DB::transaction(function () use ($authorizationActivity, $data) {
            $authorizationActivity->update(collect($data)->except('requirements')->all());
            if (array_key_exists('requirements', $data)) {
                $this->syncRequirements($authorizationActivity, $data['requirements']);
            }
        });

        return ApiResponse::success(self::row($this->fresh($authorizationActivity)), 'Activity updated.');
    }

    /** Removing an activity removes it from the register; past authorizations keep their history. */
    public function destroy(AuthorizationActivity $authorizationActivity): JsonResponse
    {
        $authorizationActivity->delete();

        return ApiResponse::success(null, 'Activity removed.');
    }

    public static function row(AuthorizationActivity $a): array
    {
        return [
            'uuid'             => $a->uuid,
            'name'             => $a->name,
            'description'      => $a->description,
            'position'         => $a->position ? ['uuid' => $a->position->uuid, 'name' => $a->position->name] : null,
            'validity_months'  => $a->validity_months,
            'authorized_count' => (int) ($a->authorized_count ?? 0),
            'requirements'     => $a->requirements->map(fn ($r) => $r->competency_id
                ? ['kind' => 'competency', 'competency' => $r->competency ? ['uuid' => $r->competency->uuid, 'name' => $r->competency->name] : null, 'min_level' => $r->min_level]
                : ['kind' => 'certificate', 'certification_type' => $r->certificationType ? ['uuid' => $r->certificationType->uuid, 'name' => $r->certificationType->name] : null])
                ->filter(fn ($r) => ($r['competency'] ?? $r['certification_type'] ?? null) !== null)
                ->values(),
        ];
    }

    private function fresh(AuthorizationActivity $a): AuthorizationActivity
    {
        return $a->fresh(['position', 'requirements.competency', 'requirements.certificationType'])->loadCount(['authorizations as authorized_count' => fn ($q) => $q->where('status', 'authorized')]);
    }

    private function validated(Request $request, ?AuthorizationActivity $activity = null): array
    {
        $data = $request->validate([
            'name'                                 => [$activity ? 'sometimes' : 'required', 'string', 'max:255',
                Rule::unique('authorization_activities', 'name')->ignore($activity?->id)->whereNull('deleted_at')],
            'description'                          => ['nullable', 'string', 'max:2000'],
            'position_uuid'                        => ['nullable', 'string', Rule::exists('positions', 'uuid')->whereNull('deleted_at')],
            'validity_months'                      => ['nullable', 'integer', 'between:1,120'],
            'requirements'                         => [$activity ? 'sometimes' : 'required', 'array', 'min:1'],
            'requirements.*.competency_uuid'       => ['nullable', 'required_without:requirements.*.certification_type_uuid', 'string', Rule::exists('competencies', 'uuid')->whereNull('deleted_at')],
            'requirements.*.min_level'             => ['nullable', 'required_with:requirements.*.competency_uuid', 'integer', 'between:1,4'],
            'requirements.*.certification_type_uuid' => ['nullable', 'string', Rule::exists('certification_types', 'uuid')->whereNull('deleted_at')],
        ], ['requirements.required' => 'Say what the activity requires: a competency level or a certificate.']);

        foreach ($data['requirements'] ?? [] as $i => $row) {
            if (!empty($row['competency_uuid']) && !empty($row['certification_type_uuid'])) {
                throw ValidationException::withMessages(["requirements.{$i}" => 'A requirement is either a competency or a certificate, not both.']);
            }
        }

        if (array_key_exists('position_uuid', $data)) {
            $data['position_id'] = $data['position_uuid'] ? Position::where('uuid', $data['position_uuid'])->value('id') : null;
            unset($data['position_uuid']);
        }

        return $data;
    }

    /** Replace the requirements (they're few; history lives on the authorizations, not here). */
    private function syncRequirements(AuthorizationActivity $activity, array $rows): void
    {
        $activity->requirements()->delete();

        foreach ($rows as $row) {
            $activity->requirements()->create(!empty($row['competency_uuid'])
                ? ['competency_id' => Competency::where('uuid', $row['competency_uuid'])->value('id'), 'min_level' => $row['min_level']]
                : ['certification_type_id' => CertificationType::where('uuid', $row['certification_type_uuid'])->value('id')]);
        }
    }
}
