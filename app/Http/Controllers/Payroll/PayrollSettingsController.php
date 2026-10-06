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
        'payroll.bonus_tax_method'            => ['required', 'in:income,gra_bonus'],
        'payroll.require_different_approvers' => ['required', 'boolean'],
        'payroll.payslip_show_employer'       => ['required', 'boolean'],
        'payroll.payslip_show_ytd'            => ['required', 'boolean'],
        'payroll.email_payslips'              => ['required', 'boolean'],
        'payroll.overtime_auto_type'          => ['required', 'boolean'],
        'payroll.overtime_eligibility'        => ['required', 'in:everyone,job_types'],
        'payroll.overtime_job_types'          => ['present', 'array'],
        'payroll.overtime_max_hours_per_day'  => ['required', 'numeric', 'between:0.5,24'],
        'payroll.overtime_max_hours_per_month'=> ['required', 'numeric', 'between:0,744'],
        'payroll.overtime_backdate_days'      => ['required', 'integer', 'between:0,366'],
        'payroll.overtime_require_reason'     => ['required', 'boolean'],
        'payroll.overtime_require_location'   => ['required', 'boolean'],
        'payroll.overtime_locations'          => ['present', 'array'],
        'payroll.time_inputs_require_approval'=> ['required', 'boolean'],
        'payroll.loans_deduct_from_final_pay' => ['required', 'boolean'],
        'payroll.arrears_months'              => ['required', 'integer', 'between:0,36'],
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
        $rules = collect(self::SETTINGS)->mapWithKeys(fn ($r, $key) => [$this->field($key) => ['sometimes', ...$r]])->all()
            + ['overtime_job_types.*' => ['string', 'max:50'], 'overtime_locations.*' => ['string', 'max:100']];
        $data = $request->validate($rules);

        foreach (array_filter($data, fn ($k) => !str_contains($k, '.'), ARRAY_FILTER_USE_KEY) as $field => $value) {
            $this->settings->set("payroll.{$field}", is_string($value) && $field === 'base_currency' ? strtoupper($value) : $value);
        }

        return ApiResponse::success($this->values() + ['features' => $this->features()], 'Payroll settings saved.');
    }

    private function values(): array
    {
        return collect(self::SETTINGS)->keys()->mapWithKeys(fn ($key) => [$this->field($key) => setting($key)])->all()
            // Job types in use, to choose eligible ones from.
            + ['job_type_options' => \App\Models\SelfService\Employee::query()->whereNotNull('job_type')->distinct()->orderBy('job_type')->pluck('job_type')];
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
