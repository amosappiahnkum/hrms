<?php

namespace App\Http\Controllers\Payroll;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Models\JobDetail;
use App\Models\Payroll\CostCentre;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** What pay is charged to. Employees without one are charged to their department. */
class CostCentreController extends Controller
{
    public function index(): JsonResponse
    {
        $counts = JobDetail::whereNotNull('cost_centre_id')->selectRaw('cost_centre_id, count(*) as n')->groupBy('cost_centre_id')->pluck('n', 'cost_centre_id');

        return ApiResponse::success(CostCentre::orderByDesc('active')->orderBy('code')->get()
            ->map(fn ($c) => self::row($c) + ['employees' => (int) ($counts[$c->id] ?? 0)])->values());
    }

    public function store(Request $request): JsonResponse
    {
        return ApiResponse::success(self::row(CostCentre::create($this->validated($request))), 'Cost centre added.', 201);
    }

    public function update(Request $request, CostCentre $costCentre): JsonResponse
    {
        $costCentre->update($this->validated($request, $costCentre));

        return ApiResponse::success(self::row($costCentre), 'Cost centre saved.');
    }

    public function destroy(CostCentre $costCentre): JsonResponse
    {
        // Employees keep it until moved (soft deleted); payslips already made keep its name.
        $costCentre->delete();

        return ApiResponse::success(null, 'Cost centre removed.');
    }

    public static function row(CostCentre $c): array
    {
        return ['uuid' => $c->uuid, 'code' => $c->code, 'name' => $c->name, 'active' => $c->active];
    }

    private function validated(Request $request, ?CostCentre $centre = null): array
    {
        $request->merge(['code' => strtoupper(trim((string) $request->input('code')))]);

        return $request->validate([
            'code'   => ['required', 'string', 'max:20', Rule::unique('cost_centres', 'code')->ignore($centre?->id)->whereNull('deleted_at')],
            'name'   => ['required', 'string', 'max:255'],
            'active' => ['boolean'],
        ], ['code.unique' => 'Another cost centre has this code.']);
    }
}
