<?php

namespace App\Http\Controllers\Payroll;

use App\Enums\Payroll\ApprovalProcess;
use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Models\Payroll\ApprovalWorkflow;
use App\Models\Payroll\LoanType;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** The loans and advances the organization offers, each with its limits, interest and approval. */
class LoanTypeController extends Controller
{
    public function index(): JsonResponse
    {
        return ApiResponse::success([
            'types'   => LoanType::with('workflow')->withCount(['loans as active_count' => fn ($q) => $q->where('status', 'active')])->orderBy('name')->get()->map(fn ($t) => self::row($t))->values(),
            'options' => [
                'interest_methods' => collect(LoanType::INTEREST_METHODS)->map(fn ($label, $value) => compact('value', 'label'))->values(),
                'workflows'        => ApprovalWorkflow::where('process', ApprovalProcess::LOAN)->orderBy('name')->get()
                    ->map(fn ($w) => ['uuid' => $w->uuid, 'name' => $w->name, 'is_default' => $w->is_default])->values(),
            ],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $type = LoanType::create($this->validated($request));

        return ApiResponse::success(self::row($type->load('workflow')), 'Loan type added.', 201);
    }

    public function update(Request $request, LoanType $loanType): JsonResponse
    {
        // Loans already requested keep the interest they were requested with.
        $loanType->update($this->validated($request));

        return ApiResponse::success(self::row($loanType->load('workflow')), 'Loan type saved.');
    }

    public function destroy(LoanType $loanType): JsonResponse
    {
        $loanType->delete();

        return ApiResponse::success(null, 'Loan type removed.');
    }

    public static function row(LoanType $t): array
    {
        return [
            'uuid'                  => $t->uuid,
            'name'                  => $t->name,
            'description'           => $t->description,
            'interest_method'       => ['value' => $t->interest_method, 'label' => LoanType::INTEREST_METHODS[$t->interest_method] ?? $t->interest_method],
            'interest_rate'         => (float) $t->interest_rate,
            'max_amount'            => $t->max_amount !== null ? (float) $t->max_amount : null,
            'max_times_basic'       => $t->max_times_basic !== null ? (float) $t->max_times_basic : null,
            'max_tenor_months'      => $t->max_tenor_months,
            'min_service_months'    => $t->min_service_months,
            'max_active_loans'      => $t->max_active_loans,
            'max_deduction_percent' => $t->max_deduction_percent !== null ? (float) $t->max_deduction_percent : null,
            'requires_guarantor'    => $t->requires_guarantor,
            'workflow'              => $t->workflow ? ['uuid' => $t->workflow->uuid, 'name' => $t->workflow->name] : null,
            'active'                => $t->active,
            'active_count'          => $t->active_count ?? null,
        ];
    }

    private function validated(Request $request): array
    {
        $data = $request->validate([
            'name'                  => ['required', 'string', 'max:100'],
            'description'           => ['nullable', 'string', 'max:1000'],
            'interest_method'       => ['required', Rule::in(array_keys(LoanType::INTEREST_METHODS))],
            'interest_rate'         => ['nullable', 'numeric', 'between:0,100', Rule::requiredIf($request->input('interest_method') !== 'none')],
            'max_amount'            => ['nullable', 'numeric', 'min:1'],
            'max_times_basic'       => ['nullable', 'numeric', 'between:0.1,60'],
            'max_tenor_months'      => ['required', 'integer', 'between:1,120'],
            'min_service_months'    => ['nullable', 'integer', 'between:1,600'],
            'max_active_loans'      => ['nullable', 'integer', 'between:1,20'],
            'max_deduction_percent' => ['nullable', 'numeric', 'between:1,100'],
            'requires_guarantor'    => ['boolean'],
            'workflow_uuid'         => ['nullable', Rule::exists('approval_workflows', 'uuid')->where('process', ApprovalProcess::LOAN->value)],
            'active'                => ['boolean'],
        ], ['interest_rate.required' => 'Enter the interest rate.']);

        $data['interest_rate'] = $data['interest_method'] === 'none' ? 0 : $data['interest_rate'];
        $data['approval_workflow_id'] = filled($data['workflow_uuid'] ?? null) ? ApprovalWorkflow::where('uuid', $data['workflow_uuid'])->value('id') : null;

        return collect($data)->except('workflow_uuid')->all();
    }
}
