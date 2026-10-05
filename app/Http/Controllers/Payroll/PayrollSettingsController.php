<?php

namespace App\Http\Controllers\Payroll;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Services\SettingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** General payroll settings: how pay is worked out for this organization. */
class PayrollSettingsController extends Controller
{
    /** setting key => validation rules */
    private const SETTINGS = [
        'payroll.base_currency'               => ['required', 'string', 'size:3'],
        'payroll.working_days_per_month'      => ['required', 'integer', 'between:1,31'],
        'payroll.hours_per_day'               => ['required', 'numeric', 'between:1,24'],
        'payroll.overtime_tax_method'         => ['required', 'in:income,gra_junior'],
        'payroll.require_different_approvers' => ['required', 'boolean'],
    ];

    public function __construct(private readonly SettingService $settings)
    {
    }

    public function show(): JsonResponse
    {
        return ApiResponse::success($this->values() + ['features' => $this->features()]);
    }

    public function update(Request $request): JsonResponse
    {
        $rules = collect(self::SETTINGS)->mapWithKeys(fn ($r, $key) => [$this->field($key) => ['sometimes', ...$r]])->all();
        $data = $request->validate($rules);

        foreach ($data as $field => $value) {
            $this->settings->set("payroll.{$field}", is_string($value) && $field === 'base_currency' ? strtoupper($value) : $value);
        }

        return ApiResponse::success($this->values() + ['features' => $this->features()], 'Payroll settings saved.');
    }

    private function values(): array
    {
        return collect(self::SETTINGS)->keys()->mapWithKeys(fn ($key) => [$this->field($key) => setting($key)])->all();
    }

    /** Which parts are on, so the settings page shows only what applies. */
    private function features(): array
    {
        return collect(['enabled', 'overtime', 'loans', 'time_inputs'])->mapWithKeys(fn ($f) => [$f => feature("payroll.{$f}")])->all();
    }

    private function field(string $key): string
    {
        return substr($key, strlen('payroll.'));
    }
}
