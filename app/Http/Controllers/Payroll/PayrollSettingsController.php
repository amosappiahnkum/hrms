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
        'payroll.base_currency'               => ['required', 'string', 'size:3'], // a known code: checked in update()
        'payroll.working_days_per_month'      => ['required', 'integer', 'between:1,31'],
        'payroll.period_start_day'            => ['required', 'integer', 'between:1,28'],
        'payroll.pay_day'                     => ['required', 'integer', 'between:0,31'],
        'payroll.hours_per_day'               => ['required', 'numeric', 'between:1,24'],
        // The normal working day; hours_per_day follows from it when it's saved.
        'payroll.work_day_start'              => ['required', 'date_format:H:i'],
        'payroll.work_day_end'                => ['required', 'date_format:H:i'],
        'payroll.work_day_break_minutes'      => ['required', 'integer', 'between:0,240'],
        'payroll.overtime_hourly_basis'       => ['required', 'in:fixed_days,period_working_days,fixed_hours'],
        'payroll.overtime_monthly_hours'      => ['required', 'numeric', 'between:1,744'],
        'payroll.exchange_rate_day'           => ['required', 'integer', 'between:0,31'],
        'payroll.separate_basic_rate'         => ['required', 'boolean'],
        'payroll.overtime_rate_type'          => ['required', 'in:basic,standard'],
        'payroll.overtime_tax_method'         => ['required', 'in:income,gra_junior'],
        'payroll.bonus_tax_method'            => ['required', 'in:income,gra_bonus'],
        'payroll.require_different_approvers' => ['required', 'boolean'],
        'payroll.payslip_show_employer'       => ['required', 'boolean'],
        'payroll.payslip_show_ytd'            => ['required', 'boolean'],
        'payroll.email_payslips'              => ['required', 'boolean'],
        'payroll.payslip_fixed_lines'         => ['present', 'array'],
        'payroll.payslip_account_number'      => ['required', 'in:masked,full'],
        'payroll.overtime_auto_type'          => ['required', 'boolean'],
        'payroll.overtime_eligibility'        => ['required', 'in:everyone,job_types'],
        'payroll.overtime_job_types'          => ['present', 'array'],
        'payroll.overtime_max_hours_per_day'  => ['required', 'numeric', 'between:0.5,24'],
        'payroll.overtime_max_hours_per_month'=> ['required', 'numeric', 'between:0,744'],
        'payroll.overtime_backdate_days'      => ['required', 'integer', 'between:0,366'],
        'payroll.overtime_mode'               => ['required', 'in:after,before,both'],
        'payroll.overtime_advance_days'       => ['required', 'integer', 'between:0,366'],
        // Kinds of day whose overtime needs a request approved before the work (in advance modes).
        'payroll.overtime_advance_for'        => ['present', 'array'],
        'payroll.overtime_pay_basis'          => ['required', 'in:approved,actual,lower'],
        'payroll.overtime_require_reason'     => ['required', 'boolean'],
        'payroll.overtime_require_location'   => ['required', 'boolean'],
        'payroll.overtime_locations'          => ['present', 'array'],
        'payroll.time_inputs_require_approval'=> ['required', 'boolean'],
        'payroll.time_source'                 => ['required', 'in:manual,timesheet'],
        'payroll.timesheet_columns'           => ['present', 'array', 'max:20'],
        'payroll.timesheet_worked_columns'    => ['present', 'array'],
        'payroll.freelance_tax'               => ['required', 'in:paye,withholding'],
        'payroll.freelance_timesheet_columns' => ['present', 'array', 'max:20'],
        'payroll.freelance_worked_columns'    => ['present', 'array'],
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
            + ['overtime_job_types.*' => ['string', 'max:50'], 'overtime_locations.*' => ['string', 'max:100'], 'payslip_fixed_lines.*' => ['string', 'max:30'],
               'timesheet_columns.*.key' => ['required', 'string', 'max:30', 'regex:/^[a-z0-9_]+$/', 'distinct'],
               'timesheet_columns.*.label' => ['required', 'string', 'max:60'],
               'timesheet_columns.*.type' => ['required', 'in:hours,check'],
               'timesheet_columns.*.feeds' => ['nullable', 'in:overtime,time_input'],
               'timesheet_columns.*.day_kind' => ['nullable', 'required_if:timesheet_columns.*.feeds,overtime', 'in:weekday,weekend,holiday'],
               'timesheet_columns.*.component' => ['nullable', 'required_if:timesheet_columns.*.feeds,time_input', 'string', 'max:30'],
               'timesheet_columns.*.requires_offshore' => ['nullable', 'boolean'],
               'timesheet_columns.*.normal' => ['nullable', 'boolean'],
               'overtime_advance_for.*' => ['string', 'in:weekday,weekend,holiday'],
               'timesheet_columns.*.sheet_column' => ['nullable', 'string', 'max:3', 'regex:/^[A-Za-z]+$/'],
               'freelance_timesheet_columns.*.key' => ['required', 'string', 'max:30', 'regex:/^[a-z0-9_]+$/', 'distinct'],
               'freelance_timesheet_columns.*.label' => ['required', 'string', 'max:60'],
               'freelance_timesheet_columns.*.type' => ['required', 'in:hours,check'],
               'freelance_timesheet_columns.*.feeds' => ['nullable', 'in:overtime,time_input'],
               'freelance_timesheet_columns.*.day_kind' => ['nullable', 'required_if:timesheet_columns.*.feeds,overtime', 'in:weekday,weekend,holiday'],
               'freelance_timesheet_columns.*.component' => ['nullable', 'required_if:timesheet_columns.*.feeds,time_input', 'string', 'max:30'],
               'freelance_timesheet_columns.*.requires_offshore' => ['nullable', 'boolean'],
               'freelance_timesheet_columns.*.sheet_column' => ['nullable', 'string', 'max:3', 'regex:/^[A-Za-z]+$/'],
               'timesheet_worked_columns.*' => ['string', 'max:30'], 'freelance_worked_columns.*' => ['string', 'max:30']];
        if ($request->has('base_currency')) {
            $request->merge(['base_currency' => \App\Support\Currencies::normalize($request->input('base_currency'))]);
            $rules['base_currency'][] = \App\Support\Currencies::rule();
        }
        $data = $request->validate($rules);
        // A column that feeds time inputs names a per-unit component.
        foreach (['timesheet_columns', 'freelance_timesheet_columns'] as $list) {
            foreach ($data[$list] ?? [] as $i => $column) {
                if (($column['feeds'] ?? null) === 'time_input' && !\App\Models\Payroll\PayComponent::where('code', strtoupper($column['component']))
                    ->where('calculation', \App\Enums\Payroll\ComponentCalculation::RATE_PER_UNIT)->exists()) {
                    throw \Illuminate\Validation\ValidationException::withMessages(["{$list}.{$i}.component" => 'Choose a per-unit pay component (e.g. Offshore day).']);
                }
            }
        }

        // Start and end are checked together, against what's saved when only one is sent.
        $start = \App\Services\Payroll\WorkingDay::minutes($data['work_day_start'] ?? setting('payroll.work_day_start', '08:00'));
        $end = \App\Services\Payroll\WorkingDay::minutes($data['work_day_end'] ?? setting('payroll.work_day_end', '17:00'));
        $break = (int) ($data['work_day_break_minutes'] ?? setting('payroll.work_day_break_minutes', 60));
        $workDay = array_intersect_key($data, array_flip(['work_day_start', 'work_day_end', 'work_day_break_minutes']));
        if ($workDay && \App\Services\Payroll\WorkingDay::hoursFor((int) $start, (int) $end, $break) < 1) {
            throw \Illuminate\Validation\ValidationException::withMessages(['work_day_end' => 'The working day must be at least an hour after the break.']);
        }

        foreach (array_filter($data, fn ($k) => !str_contains($k, '.'), ARRAY_FILTER_USE_KEY) as $field => $value) {
            $this->settings->set("payroll.{$field}", is_string($value) && $field === 'base_currency' ? strtoupper($value) : $value);
        }
        // The overtime hourly rate's hours a day follow the working day, so the rate and the timesheet agree.
        if ($workDay) {
            $this->settings->set('payroll.hours_per_day', \App\Services\Payroll\WorkingDay::hoursFor((int) $start, (int) $end, $break));
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
        return collect(['enabled', 'overtime', 'loans', 'time_inputs', 'timesheets'])->mapWithKeys(fn ($f) => [$f => feature("payroll.{$f}")])->all();
    }

    private function field(string $key): string
    {
        return substr($key, strlen('payroll.'));
    }
}
