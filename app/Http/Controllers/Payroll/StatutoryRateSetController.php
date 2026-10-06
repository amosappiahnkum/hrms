<?php

namespace App\Http\Controllers\Payroll;

use App\Exceptions\UserFacingException;
use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Models\Payroll\StatutoryRateSet;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Statutory rates as dated sets, maintained by HR: a change of rates is a new set from a date, so
 * past periods keep theirs. A set is confirmed (checked against GRA/SSNIT) before pay runs use it.
 */
class StatutoryRateSetController extends Controller
{
    public function index(): JsonResponse
    {
        $current = StatutoryRateSet::inForceOn(now());

        return ApiResponse::success([
            'current_uuid' => $current?->uuid,
            'sets'         => StatutoryRateSet::with('confirmer')->orderByDesc('effective_from')->get()->map(fn ($s) => $this->row($s))->values(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $set = StatutoryRateSet::create($this->validated($request) + ['created_by' => $request->user()->id]);

        return ApiResponse::success($this->row($set), 'Rates added. Confirm them once checked.', 201);
    }

    /** Editing clears the confirmation: changed figures need checking again. */
    public function update(Request $request, StatutoryRateSet $statutoryRateSet): JsonResponse
    {
        $this->ensureNotUsed($statutoryRateSet);
        $statutoryRateSet->update($this->validated($request, $statutoryRateSet) + ['confirmed_at' => null, 'confirmed_by' => null]);

        return ApiResponse::success($this->row($statutoryRateSet->fresh('confirmer')), 'Rates saved. Confirm them once checked.');
    }

    public function confirm(Request $request, StatutoryRateSet $statutoryRateSet): JsonResponse
    {
        $statutoryRateSet->update(['confirmed_at' => now(), 'confirmed_by' => $request->user()->id]);

        return ApiResponse::success($this->row($statutoryRateSet->fresh('confirmer')), 'Rates confirmed.');
    }

    public function destroy(StatutoryRateSet $statutoryRateSet): JsonResponse
    {
        $this->ensureNotUsed($statutoryRateSet);
        if ($statutoryRateSet->isConfirmed()) {
            throw new UserFacingException('Confirmed rates can\'t be removed. Add new rates from a later date instead.');
        }
        $statutoryRateSet->delete();

        return ApiResponse::success(null, 'Rates removed.');
    }

    /** Rates an approved or paid pay run used are part of its record. */
    private function ensureNotUsed(StatutoryRateSet $set): void
    {
        if (\App\Models\Payroll\PayRun::where('statutory_rate_set_id', $set->id)->whereIn('status', ['approved', 'paid'])->exists()) {
            throw new UserFacingException('An approved pay run used these rates, so they can\'t change. Add new rates from a later date instead.');
        }
    }

    private function validated(Request $request, ?StatutoryRateSet $set = null): array
    {
        $data = $request->validate([
            'name'                                 => ['required', 'string', 'max:255'],
            'effective_from'                       => ['required', 'date', Rule::unique('statutory_rate_sets', 'effective_from')->ignore($set?->id)->whereNull('deleted_at')],
            'notes'                                => ['nullable', 'string', 'max:5000'],
            'ssnit'                                => ['required', 'array'],
            'ssnit.employee_rate'                  => ['required', 'numeric', 'between:0,100'],
            'ssnit.employer_rate'                  => ['required', 'numeric', 'between:0,100'],
            'ssnit.tier1_rate'                     => ['required', 'numeric', 'between:0,100'],
            'ssnit.tier2_rate'                     => ['required', 'numeric', 'between:0,100'],
            'ssnit.max_insurable_earnings'         => ['nullable', 'numeric', 'min:0'],
            'paye_bands'                           => ['required', 'array', 'min:1', 'max:20'],
            'paye_bands.*.limit'                   => ['nullable', 'numeric', 'gt:0'],
            'paye_bands.*.rate'                    => ['required', 'numeric', 'between:0,100'],
            'reliefs'                              => ['nullable', 'array', 'max:30'],
            'reliefs.*.code'                       => ['required', 'string', 'max:50', 'distinct'],
            'reliefs.*.name'                       => ['required', 'string', 'max:100'],
            'reliefs.*.annual_amount'              => ['nullable', 'numeric', 'min:0', 'required_without:reliefs.*.percent_of_income'],
            'reliefs.*.max_units'                  => ['nullable', 'integer', 'min:1'],
            'reliefs.*.percent_of_income'          => ['nullable', 'numeric', 'between:0,100'],
            'tier3_relief_limit_percent'           => ['nullable', 'numeric', 'between:0,100'],
            'non_resident_rate'                    => ['sometimes', 'numeric', 'between:0,100'],
            'overtime_tax'                         => ['nullable', 'array'],
            'overtime_tax.annual_basic_threshold'  => ['required_with:overtime_tax', 'numeric', 'min:0'],
            'overtime_tax.percent_of_basic'        => ['required_with:overtime_tax', 'numeric', 'between:0,100'],
            'overtime_tax.lower_rate'              => ['required_with:overtime_tax', 'numeric', 'between:0,100'],
            'overtime_tax.higher_rate'             => ['required_with:overtime_tax', 'numeric', 'between:0,100'],
            'bonus_tax'                            => ['nullable', 'array'],
            'bonus_tax.rate'                       => ['required_with:bonus_tax', 'numeric', 'between:0,100'],
            'bonus_tax.percent_of_annual_basic'    => ['required_with:bonus_tax', 'numeric', 'between:0,100'],
        ]);

        // Bands in order: every one has a width except the last, which takes the rest.
        $bands = array_values($data['paye_bands']);
        foreach ($bands as $i => $band) {
            $last = $i === count($bands) - 1;
            if ($last !== ($band['limit'] === null || $band['limit'] === '')) {
                throw ValidationException::withMessages(["paye_bands.{$i}.limit" => $last
                    ? 'The last band takes the rest of the income: leave its amount empty.'
                    : 'Every band but the last needs an amount.']);
            }
        }
        $data['paye_bands'] = array_map(fn ($b) => ['limit' => $b['limit'] === null || $b['limit'] === '' ? null : (float) $b['limit'], 'rate' => (float) $b['rate']], $bands);

        // The tiers share out exactly what employee and employer pay.
        $s = $data['ssnit'];
        if (abs(($s['tier1_rate'] + $s['tier2_rate']) - ($s['employee_rate'] + $s['employer_rate'])) > 0.001) {
            throw ValidationException::withMessages(['ssnit.tier2_rate' => 'Tier 1 and Tier 2 must add up to the employee and employer rates together.']);
        }

        return $data;
    }

    private function row(StatutoryRateSet $s): array
    {
        return [
            'uuid'                       => $s->uuid,
            'name'                       => $s->name,
            'effective_from'             => $s->effective_from->toDateString(),
            'notes'                      => $s->notes,
            'ssnit'                      => $s->ssnit,
            'paye_bands'                 => $s->paye_bands,
            'reliefs'                    => $s->reliefs ?? [],
            'tier3_relief_limit_percent' => $s->tier3_relief_limit_percent !== null ? (float) $s->tier3_relief_limit_percent : null,
            'non_resident_rate'          => (float) $s->non_resident_rate,
            'overtime_tax'               => $s->overtime_tax,
            'bonus_tax'                  => $s->bonus_tax,
            'confirmed_at'               => $s->confirmed_at?->toIso8601String(),
            'confirmed_by'               => $s->confirmer?->name,
        ];
    }
}
