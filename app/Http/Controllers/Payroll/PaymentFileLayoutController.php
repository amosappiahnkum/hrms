<?php

namespace App\Http\Controllers\Payroll;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Models\Payroll\PaymentFileLayout;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Payment file layouts: the columns a bank (or mobile money) upload expects. */
class PaymentFileLayoutController extends Controller
{
    public function index(): JsonResponse
    {
        return ApiResponse::success([
            'layouts' => PaymentFileLayout::orderBy('name')->get()->map(fn ($l) => $this->row($l))->values(),
            'fields'  => collect(PaymentFileLayout::FIELDS)->map(fn ($label, $value) => ['value' => $value, 'label' => $label])->values(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        return ApiResponse::success($this->row(PaymentFileLayout::create($this->validated($request))), 'Layout added.', 201);
    }

    public function update(Request $request, PaymentFileLayout $paymentFileLayout): JsonResponse
    {
        $paymentFileLayout->update($this->validated($request));

        return ApiResponse::success($this->row($paymentFileLayout), 'Layout saved.');
    }

    public function destroy(PaymentFileLayout $paymentFileLayout): JsonResponse
    {
        $paymentFileLayout->delete();

        return ApiResponse::success(null, 'Layout removed.');
    }

    private function validated(Request $request): array
    {
        $data = $request->validate([
            'name'              => ['required', 'string', 'max:255'],
            'payment_method'    => ['required', Rule::in(['bank', 'mobile_money'])],
            // A bank from the banks list: only the people paid through it.
            'bank_uuid'         => ['nullable', 'uuid', Rule::exists('banks', 'uuid')->whereNull('deleted_at')],
            'format'            => ['required', Rule::in(['csv', 'xlsx'])],
            'delimiter'         => ['required_if:format,csv', 'nullable', Rule::in([',', ';', '|', '\t'])],
            'include_header'    => ['boolean'],
            'columns'           => ['required', 'array', 'min:1', 'max:30'],
            'columns.*.field'   => ['required', Rule::in(array_keys(PaymentFileLayout::FIELDS))],
            'columns.*.heading' => ['required', 'string', 'max:100'],
        ]) + ['delimiter' => ','];
        $data['bank_id'] = $data['payment_method'] === 'bank' && !empty($data['bank_uuid'])
            ? \App\Models\Payroll\Bank::where('uuid', $data['bank_uuid'])->value('id') : null;
        unset($data['bank_uuid']);

        return $data;
    }

    private function row(PaymentFileLayout $l): array
    {
        return $l->only(['uuid', 'name', 'payment_method', 'format', 'delimiter', 'include_header', 'columns'])
            + ['bank' => $l->bank ? BankController::row($l->bank) : null];
    }
}
