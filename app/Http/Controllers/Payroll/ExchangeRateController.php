<?php

namespace App\Http\Controllers\Payroll;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Models\Payroll\ExchangeRate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Dated exchange rates: pay set in another currency is converted at the rate in force on the run's rate date.
 * A `basic` rate (optional) converts basic salary.
 */
class ExchangeRateController extends Controller
{
    public function index(): JsonResponse
    {
        return ApiResponse::success([
            'base_currency' => strtoupper((string) setting('payroll.base_currency', 'GHS')),
            'rates'         => ExchangeRate::orderBy('currency')->orderBy('rate_type')->orderByDesc('effective_date')->get()->map(fn ($r) => $this->row($r))->values(),
            'types'         => [
                ['value' => ExchangeRate::STANDARD, 'label' => 'Standard (allowances and everything else)'],
                ['value' => ExchangeRate::BASIC, 'label' => 'Basic salary'],
            ],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $rate = ExchangeRate::create($this->validated($request) + ['rate_type' => ExchangeRate::STANDARD, 'created_by' => $request->user()->id]);

        return ApiResponse::success($this->row($rate), 'Rate added.', 201);
    }

    public function update(Request $request, ExchangeRate $exchangeRate): JsonResponse
    {
        $this->ensureNotUsed($exchangeRate);
        $exchangeRate->update($this->validated($request, $exchangeRate));

        return ApiResponse::success($this->row($exchangeRate), 'Rate saved.');
    }

    public function destroy(ExchangeRate $exchangeRate): JsonResponse
    {
        $this->ensureNotUsed($exchangeRate);
        $exchangeRate->delete();

        return ApiResponse::success(null, 'Rate removed.');
    }

    /** A rate an approved or paid pay run used is part of its record. */
    private function ensureNotUsed(ExchangeRate $rate): void
    {
        $date = $rate->effective_date->toDateString();
        $run = \App\Models\Payroll\PayRun::whereIn('status', ['approved', 'paid'])->whereNotNull('exchange_rates')->get()
            ->first(fn ($run) => (($run->exchange_rates[$rate->currency][$rate->rate_type]['date'] ?? null) === $date));
        if ($run) {
            throw new \App\Exceptions\UserFacingException("{$run->name} used this rate and has been approved, so it can't change. Add a new rate from a later date instead.");
        }
    }

    private function validated(Request $request, ?ExchangeRate $rate = null): array
    {
        $request->merge(['currency' => \App\Support\Currencies::normalize($request->input('currency'))]);
        $base = strtoupper((string) setting('payroll.base_currency', 'GHS'));

        return $request->validate([
            'currency' => ['required', \App\Support\Currencies::rule(), Rule::notIn([$base])],
            'rate_type'      => ['sometimes', Rule::in(ExchangeRate::TYPES)],
            'effective_date' => ['required', 'date',
                Rule::unique('exchange_rates', 'effective_date')
                    ->where('currency', $request->input('currency'))->where('rate_type', $request->input('rate_type', $rate?->rate_type ?? ExchangeRate::STANDARD))
                    ->ignore($rate?->id)->whereNull('deleted_at')],
            'rate'     => ['required', 'numeric', 'gt:0'],
            'notes'    => ['nullable', 'string', 'max:255'],
        ], [
            'currency.not_in' => "{$base} is the base currency: it needs no rate.",
            'effective_date.unique' => 'There is already a rate of this type for this currency from that date. Edit it instead.',
        ]);
    }

    private function row(ExchangeRate $r): array
    {
        return [
            'uuid' => $r->uuid, 'currency' => $r->currency, 'rate_type' => $r->rate_type, 'effective_date' => $r->effective_date->toDateString(),
            'rate' => (float) $r->rate, 'notes' => $r->notes,
        ];
    }
}
