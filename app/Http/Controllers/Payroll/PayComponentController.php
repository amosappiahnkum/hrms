<?php

namespace App\Http\Controllers\Payroll;

use App\Enums\Payroll\ComponentCalculation;
use App\Enums\Payroll\ComponentKind;
use App\Exceptions\UserFacingException;
use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Models\Payroll\PayComponent;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/** The pay components HR defines: how each payslip line is worked out and treated for tax and SSNIT. */
class PayComponentController extends Controller
{
    public function index(): JsonResponse
    {
        return ApiResponse::success([
            'components' => PayComponent::orderBy('kind')->orderBy('sort_order')->orderBy('name')->get()->map(fn ($c) => $this->row($c))->values(),
            'options'    => [
                'kinds'        => collect(ComponentKind::cases())->map(fn ($k) => ['value' => $k->value, 'label' => $k->label()]),
                'calculations' => collect(ComponentCalculation::cases())->map(fn ($c) => [
                    'value' => $c->value, 'label' => $c->label(), 'needs_rate' => $c->needsRate(), 'is_money' => $c->isMoney(),
                ]),
            ],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $component = PayComponent::create($this->validated($request));

        return ApiResponse::success($this->row($component), 'Component added.', 201);
    }

    public function update(Request $request, PayComponent $payComponent): JsonResponse
    {
        $data = $this->validated($request, $payComponent);
        if ($payComponent->is_system) {
            // The built-in basic salary keeps what makes it basic.
            $data = collect($data)->only(['name', 'show_on_payslip', 'sort_order', 'account_code'])->all();
        }
        $payComponent->update($data);

        return ApiResponse::success($this->row($payComponent), 'Component saved.');
    }

    public function destroy(PayComponent $payComponent): JsonResponse
    {
        if ($payComponent->is_system) {
            throw new UserFacingException("{$payComponent->name} is built in and can't be removed.");
        }
        $payComponent->delete();

        return ApiResponse::success(null, 'Component removed.');
    }

    private function validated(Request $request, ?PayComponent $component = null): array
    {
        $request->merge([
            // Left empty: made from the name (a new component), or kept (an existing one).
            'code'     => filled($request->input('code'))
                ? self::codeOf((string) $request->input('code'))
                : ($component?->code ?? self::uniqueCode((string) $request->input('name'))),
            'currency' => \App\Support\Currencies::normalize($request->input('currency')),
        ]);

        $data = $request->validate([
            'code'             => ['required', 'string', 'max:30', Rule::unique('pay_components', 'code')->ignore($component?->id)->whereNull('deleted_at')],
            'name'             => ['required', 'string', 'max:255'],
            'kind'             => ['required', Rule::enum(ComponentKind::class)],
            'calculation'      => ['required', Rule::enum(ComponentCalculation::class)],
            'rate'             => ['nullable', 'numeric', 'min:0'],
            'currency'         => ['nullable', \App\Support\Currencies::rule()],
            'unit'             => ['nullable', 'string', 'max:30'],
            'taxable'          => ['boolean'],
            'is_bonus'         => ['boolean'],
            'ssnit_applicable' => ['boolean'],
            'recurring'        => ['boolean'],
            'prorate'          => ['boolean'],
            'show_on_payslip'  => ['boolean'],
            'sort_order'       => ['nullable', 'integer', 'between:0,1000'],
            'account_code'     => ['nullable', 'string', 'max:50'],
            'active'           => ['boolean'],
        ]);

        $calculation = ComponentCalculation::from($data['calculation']);
        if ($calculation->needsRate() && ($data['rate'] ?? null) === null) {
            throw ValidationException::withMessages(['rate' => match ($calculation) {
                ComponentCalculation::PERCENT_OF_BASIC  => 'Enter the percentage.',
                ComponentCalculation::HOURLY_MULTIPLIER => 'Enter the multiplier, e.g. 1.5.',
                default                                 => 'Enter the amount.',
            }]);
        }
        if ($calculation === ComponentCalculation::RATE_PER_UNIT && blank($data['unit'] ?? null)) {
            throw ValidationException::withMessages(['unit' => 'Say what the rate is per, e.g. day.']);
        }

        // Fields that don't apply are cleared, so the record says only what it means.
        if (!$calculation->needsRate()) {
            $data['rate'] = null;
        }
        if (!$calculation->isMoney()) {
            $data['currency'] = null;
        }
        if ($calculation !== ComponentCalculation::RATE_PER_UNIT) {
            $data['unit'] = null;
        }
        if ($data['kind'] !== ComponentKind::EARNING->value) {
            $data['ssnit_applicable'] = false;
            $data['is_bonus'] = false;
        }
        $data['sort_order'] ??= 100;

        return $data;
    }

    /** A code as stored: upper case, letters, digits and underscores. */
    private static function codeOf(string $text): string
    {
        return trim(strtoupper(preg_replace('/[^A-Za-z0-9]+/', '_', $text)), '_');
    }

    /** A code made from a name, not used by another component: TRANSPORT_ALLOWANCE, then TRANSPORT_ALLOWANCE_2… */
    private static function uniqueCode(string $name): string
    {
        $base = substr(self::codeOf($name), 0, 26) ?: 'COMPONENT';
        $base = rtrim($base, '_');
        $code = $base;
        for ($n = 2; PayComponent::where('code', $code)->exists(); $n++) {
            $code = "{$base}_{$n}";
        }

        return $code;
    }

    private function row(PayComponent $c): array
    {
        return [
            'uuid'             => $c->uuid,
            'code'             => $c->code,
            'name'             => $c->name,
            'kind'             => ['value' => $c->kind->value, 'label' => $c->kind->label()],
            'calculation'      => ['value' => $c->calculation->value, 'label' => $c->calculation->label()],
            'rate'             => $c->rate !== null ? (float) $c->rate : null,
            'currency'         => $c->currency,
            'unit'             => $c->unit,
            'taxable'          => $c->taxable,
            'is_bonus'         => $c->is_bonus,
            'ssnit_applicable' => $c->ssnit_applicable,
            'recurring'        => $c->recurring,
            'prorate'          => $c->prorate,
            'show_on_payslip'  => $c->show_on_payslip,
            'sort_order'       => $c->sort_order,
            'account_code'     => $c->account_code,
            'active'           => $c->active,
            'is_system'        => $c->is_system,
        ];
    }
}
