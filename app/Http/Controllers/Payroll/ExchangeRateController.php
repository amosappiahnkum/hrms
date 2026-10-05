<?php

namespace App\Http\Controllers\Payroll;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Models\Payroll\ExchangeRate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Yearly exchange rates: pay set in another currency is converted at its year's rate. */
class ExchangeRateController extends Controller
{
    public function index(): JsonResponse
    {
        return ApiResponse::success([
            'base_currency' => strtoupper((string) setting('payroll.base_currency', 'GHS')),
            'rates'         => ExchangeRate::orderBy('currency')->orderByDesc('year')->get()->map(fn ($r) => $this->row($r))->values(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $rate = ExchangeRate::create($this->validated($request) + ['created_by' => $request->user()->id]);

        return ApiResponse::success($this->row($rate), 'Rate added.', 201);
    }

    public function update(Request $request, ExchangeRate $exchangeRate): JsonResponse
    {
        $exchangeRate->update($this->validated($request, $exchangeRate));

        return ApiResponse::success($this->row($exchangeRate), 'Rate saved.');
    }

    public function destroy(ExchangeRate $exchangeRate): JsonResponse
    {
        $exchangeRate->delete();

        return ApiResponse::success(null, 'Rate removed.');
    }

    private function validated(Request $request, ?ExchangeRate $rate = null): array
    {
        $request->merge(['currency' => strtoupper((string) $request->input('currency'))]);
        $base = strtoupper((string) setting('payroll.base_currency', 'GHS'));

        return $request->validate([
            'currency' => ['required', 'string', 'size:3', 'alpha', Rule::notIn([$base])],
            'year'     => ['required', 'integer', 'between:2000,2100',
                Rule::unique('exchange_rates', 'year')->where('currency', $request->input('currency'))->ignore($rate?->id)->whereNull('deleted_at')],
            'rate'     => ['required', 'numeric', 'gt:0'],
            'notes'    => ['nullable', 'string', 'max:255'],
        ], [
            'currency.not_in' => "{$base} is the base currency: it needs no rate.",
            'year.unique'     => 'There is already a rate for this currency and year. Edit it instead.',
        ]);
    }

    private function row(ExchangeRate $r): array
    {
        return ['uuid' => $r->uuid, 'currency' => $r->currency, 'year' => $r->year, 'rate' => (float) $r->rate, 'notes' => $r->notes];
    }
}
