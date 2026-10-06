<?php

namespace App\Http\Controllers\Payroll;

use App\Enums\Payroll\ComponentCalculation;
use App\Enums\Payroll\ComponentKind;
use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Models\Payroll\OvertimeType;
use App\Models\Payroll\PayComponent;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** The kinds of overtime the organization pays, each priced by an hourly earning component. */
class OvertimeTypeController extends Controller
{
    public function index(): JsonResponse
    {
        return ApiResponse::success([
            'types'   => OvertimeType::with('component')->orderBy('sort_order')->orderBy('name')->get()->map(fn ($t) => self::row($t))->values(),
            'options' => [
                'applies_on' => collect(OvertimeType::APPLIES_ON)->map(fn ($label, $value) => compact('value', 'label'))->values(),
                'components' => $this->components()->map(fn ($c) => ['uuid' => $c->uuid, 'name' => $c->name, 'code' => $c->code, 'rate' => (float) $c->rate, 'calculation' => $c->calculation->label()])->values(),
            ],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $type = OvertimeType::create($this->validated($request));

        return ApiResponse::success(self::row($type->load('component')), 'Overtime type added.', 201);
    }

    public function update(Request $request, OvertimeType $overtimeType): JsonResponse
    {
        $overtimeType->update($this->validated($request));

        return ApiResponse::success(self::row($overtimeType->load('component')), 'Overtime type saved.');
    }

    public function destroy(OvertimeType $overtimeType): JsonResponse
    {
        // Past requests keep pointing at it (soft deleted).
        $overtimeType->delete();

        return ApiResponse::success(null, 'Overtime type removed.');
    }

    public static function row(OvertimeType $t): array
    {
        return [
            'uuid'       => $t->uuid,
            'name'       => $t->name,
            'applies_on' => ['value' => $t->applies_on, 'label' => OvertimeType::APPLIES_ON[$t->applies_on] ?? $t->applies_on],
            'component'  => $t->component ? ['uuid' => $t->component->uuid, 'name' => $t->component->name, 'code' => $t->component->code, 'rate' => (float) $t->component->rate] : null,
            'active'     => $t->active,
            'sort_order' => $t->sort_order,
        ];
    }

    /** Earning components that pay by the hour. */
    private function components()
    {
        return PayComponent::where('kind', ComponentKind::EARNING)->where('active', true)
            ->whereIn('calculation', [ComponentCalculation::HOURLY_MULTIPLIER, ComponentCalculation::RATE_PER_UNIT])
            ->orderBy('sort_order')->orderBy('name')->get();
    }

    private function validated(Request $request): array
    {
        $data = $request->validate([
            'name'               => ['required', 'string', 'max:100'],
            'pay_component_uuid' => ['required', Rule::in($this->components()->pluck('uuid'))],
            'applies_on'         => ['required', Rule::in(array_keys(OvertimeType::APPLIES_ON))],
            'active'             => ['boolean'],
            'sort_order'         => ['nullable', 'integer', 'between:0,1000'],
        ], ['pay_component_uuid.in' => 'Choose an hourly earning component (add one under Pay components).']);

        $data['pay_component_id'] = PayComponent::where('uuid', $data['pay_component_uuid'])->value('id');
        $data['sort_order'] ??= 100;

        return collect($data)->except('pay_component_uuid')->all();
    }
}
