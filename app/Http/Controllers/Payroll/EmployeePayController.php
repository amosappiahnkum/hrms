<?php

namespace App\Http\Controllers\Payroll;

use App\Exceptions\UserFacingException;
use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Models\Payroll\EmployeePayComponent;
use App\Models\Payroll\EmployeePayProfile;
use App\Models\Payroll\PayComponent;
use App\Models\Payroll\StatutoryRateSet;
use App\Models\SelfService\Employee;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Employees' pay details: a dated profile (basic, currency, payment, pensions, reliefs) and their
 * recurring components. Correcting edits the profile in force; a change from a date adds a new one.
 */
class EmployeePayController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $request->validate(['status' => ['nullable', Rule::in(['all', 'no_profile', 'with_profile'])]]);
        $today = now()->toDateString();

        $page = Employee::query()
            ->with(['department', 'jobDetail.position', 'contactDetail', 'payProfiles' => fn ($q) => $q->inForceOn($today)])
            ->when($request->search, fn ($q, $v) => $q->where(fn ($w) => $w->where('first_name', 'like', "%{$v}%")->orWhere('last_name', 'like', "%{$v}%")->orWhere('staff_id', 'like', "%{$v}%")))
            ->when($request->department_uuid, fn ($q, $v) => $q->whereHas('department', fn ($d) => $d->where('uuid', $v)))
            ->when($request->status === 'no_profile', fn ($q) => $q->whereDoesntHave('payProfiles'))
            ->when($request->status === 'with_profile', fn ($q) => $q->whereHas('payProfiles'))
            ->orderBy('first_name')->orderBy('last_name')
            ->paginate($request->integer('per_page', 25));

        return ApiResponse::success([
            'data' => collect($page->items())->map(function (Employee $e) {
                $profile = $e->payProfiles->first();

                return [
                    'employee' => $this->employee($e),
                    'profile'  => $profile ? [
                        'basic_salary'   => (float) $profile->basic_salary,
                        'currency'       => $profile->currency ?? $this->base(),
                        'payment_method' => $profile->payment_method,
                    ] : null,
                    'missing'  => $profile ? $profile->gaps($e->ssnit_number, $e->contactDetail?->ghana_card_number) : ['Pay details'],
                ];
            })->values(),
            'meta' => ['current_page' => $page->currentPage(), 'per_page' => $page->perPage(), 'total' => $page->total(), 'last_page' => $page->lastPage()],
        ]);
    }

    public function show(Employee $employee): JsonResponse
    {
        return ApiResponse::success($this->detail($employee, masked: false));
    }

    /** Self-service: the signed-in employee's own pay details (read-only, payment numbers masked). */
    public function mine(Request $request): JsonResponse
    {
        $employee = Employee::find($request->user()->employee_id);
        abort_unless($employee, 404, 'Your account is not linked to an employee record.');

        return ApiResponse::success($this->detail($employee, masked: true));
    }

    /**
     * mode=correct: fix the profile in force (or create the first one).
     * mode=change: a new profile from a later date (e.g. a raise), keeping the old one for past periods.
     */
    public function saveProfile(Request $request, Employee $employee): JsonResponse
    {
        $current = $employee->payProfiles()->inForceOn(now())->first() ?? $employee->payProfiles()->latest('effective_from')->first();
        $data = $this->validatedProfile($request, $current);

        DB::transaction(function () use ($request, $employee, $current, $data) {
            if (array_key_exists('ssnit_number', $data)) {
                $employee->update(['ssnit_number' => $data['ssnit_number']]);
            }
            unset($data['ssnit_number'], $data['mode']);

            if ($request->input('mode') === 'change') {
                $employee->payProfiles()->create($data + collect($current?->only((new EmployeePayProfile())->getFillable()) ?? [])->except(['employee_id', 'effective_from', 'created_by'])->all() + ['created_by' => $request->user()->id]);
            } elseif ($current) {
                $current->update($data);
            } else {
                $employee->payProfiles()->create($data + ['effective_from' => $data['effective_from'] ?? now()->startOfMonth()->toDateString(), 'created_by' => $request->user()->id]);
            }
        });

        return ApiResponse::success($this->detail($employee->fresh(), masked: false), $request->input('mode') === 'change' ? 'Change saved from ' . Carbon::parse($data['effective_from'])->format('j M Y') . '.' : 'Pay details saved.');
    }

    public function addComponent(Request $request, Employee $employee): JsonResponse
    {
        $data = $this->validatedComponent($request);
        $employee->payComponents()->create($data);

        return ApiResponse::success($this->detail($employee->fresh(), masked: false), 'Component given.', 201);
    }

    public function updateComponent(Request $request, Employee $employee, EmployeePayComponent $employeePayComponent): JsonResponse
    {
        abort_unless($employeePayComponent->employee_id === $employee->id, 404);
        $employeePayComponent->update($this->validatedComponent($request, $employeePayComponent));

        return ApiResponse::success($this->detail($employee->fresh(), masked: false), 'Component saved.');
    }

    public function removeComponent(Employee $employee, EmployeePayComponent $employeePayComponent): JsonResponse
    {
        abort_unless($employeePayComponent->employee_id === $employee->id, 404);
        $employeePayComponent->delete();

        return ApiResponse::success($this->detail($employee->fresh(), masked: false), 'Component removed.');
    }

    private function validatedProfile(Request $request, ?EmployeePayProfile $current): array
    {
        $change = $request->input('mode') === 'change';
        if ($change && !$current) {
            throw new UserFacingException('There are no pay details to change yet. Save the first ones instead.');
        }
        $request->merge(['currency' => $request->filled('currency') ? strtoupper($request->input('currency')) : null]);

        $data = $request->validate([
            'mode'                  => ['required', Rule::in(['correct', 'change'])],
            'effective_from'        => [$change ? 'required' : 'nullable', 'date', ...($change ? ['after:' . $current->effective_from->toDateString()] : [])],
            'basic_salary'          => ['required', 'numeric', 'min:0'],
            'currency'              => ['nullable', 'string', 'size:3', 'alpha'],
            'payment_method'        => ['required', Rule::in(array_keys(EmployeePayProfile::PAYMENT_METHODS))],
            'bank_name'             => ['nullable', 'required_if:payment_method,bank', 'string', 'max:255'],
            'bank_branch'           => ['nullable', 'string', 'max:255'],
            'account_name'          => ['nullable', 'string', 'max:255'],
            'account_number'        => ['nullable', 'required_if:payment_method,bank', 'string', 'max:50'],
            'mobile_money_provider' => ['nullable', 'required_if:payment_method,mobile_money', 'string', 'max:50'],
            'mobile_money_number'   => ['nullable', 'required_if:payment_method,mobile_money', 'string', 'max:20'],
            'tin'                   => ['nullable', 'string', 'max:30'],
            'ssnit_number'          => ['sometimes', 'nullable', 'string', 'max:30'],
            'tier2_scheme'          => ['nullable', 'string', 'max:255'],
            'tier3_scheme'          => ['nullable', 'string', 'max:255'],
            'tier3_percent'         => ['nullable', 'numeric', 'between:0,100'],
            'reliefs'               => ['nullable', 'array'],
            'reliefs.*.code'        => ['required', 'string', 'distinct'],
            'reliefs.*.units'       => ['nullable', 'integer', 'min:1'],
            'tax_resident'          => ['boolean'],
            'overtime_eligible'     => ['nullable', 'boolean'],
            'notes'                 => ['nullable', 'string', 'max:2000'],
        ], ['effective_from.after' => 'A change must start after the current details (from ' . $current?->effective_from->format('j M Y') . ').']);

        // Reliefs claimed must be ones the statutory rates define, within their limits.
        $defined = collect(StatutoryRateSet::inForceOn(now())?->reliefs ?? [])->keyBy('code');
        foreach ($data['reliefs'] ?? [] as $i => $relief) {
            $def = $defined->get($relief['code']);
            if (!$def) {
                throw ValidationException::withMessages(["reliefs.{$i}.code" => 'Not a relief in the statutory rates.']);
            }
            if ($def['max_units'] && ($relief['units'] ?? 1) > $def['max_units']) {
                throw ValidationException::withMessages(["reliefs.{$i}.units" => "{$def['name']}: up to {$def['max_units']}."]);
            }
        }

        // Payment details that don't apply are cleared.
        if ($data['payment_method'] !== 'bank') {
            $data = array_merge($data, ['bank_name' => null, 'bank_branch' => null, 'account_name' => null, 'account_number' => null]);
        }
        if ($data['payment_method'] !== 'mobile_money') {
            $data = array_merge($data, ['mobile_money_provider' => null, 'mobile_money_number' => null]);
        }
        if ($data['currency'] === $this->base()) {
            $data['currency'] = null;
        }

        return $data;
    }

    private function validatedComponent(Request $request, ?EmployeePayComponent $existing = null): array
    {
        $data = $request->validate([
            'pay_component_uuid' => [$existing ? 'prohibited' : 'required', 'string'],
            'amount'             => ['nullable', 'numeric', 'min:0'],
            'effective_from'     => ['required', 'date'],
            'effective_to'       => ['nullable', 'date', 'after_or_equal:effective_from'],
            'notes'              => ['nullable', 'string', 'max:255'],
        ]);

        if (!$existing) {
            $component = PayComponent::where('uuid', $data['pay_component_uuid'])->where('active', true)->where('recurring', true)->where('is_system', false)->first();
            if (!$component) {
                throw ValidationException::withMessages(['pay_component_uuid' => 'Choose an active recurring component.']);
            }
            if ($component->rate === null && ($data['amount'] ?? null) === null) {
                throw ValidationException::withMessages(['amount' => "{$component->name} has no standard rate: enter the amount."]);
            }
            $data['pay_component_id'] = $component->id;
            unset($data['pay_component_uuid']);
        }

        return $data;
    }

    private function detail(Employee $employee, bool $masked): array
    {
        $employee->loadMissing(['department', 'jobDetail.position', 'contactDetail']);
        $profiles = $employee->payProfiles()->with('employee')->orderByDesc('effective_from')->get();
        $current = $profiles->first(fn ($p) => $p->effective_from->lte(now())) ?? $profiles->last();
        $mask = fn (?string $n) => $n && $masked ? '•••• ' . substr($n, -4) : $n;

        $profile = fn (EmployeePayProfile $p) => [
            'uuid'                  => $p->uuid,
            'effective_from'        => $p->effective_from->toDateString(),
            'basic_salary'          => (float) $p->basic_salary,
            'currency'              => $p->currency ?? $this->base(),
            'payment_method'        => ['value' => $p->payment_method, 'label' => EmployeePayProfile::PAYMENT_METHODS[$p->payment_method] ?? $p->payment_method],
            'bank_name'             => $p->bank_name,
            'bank_branch'           => $p->bank_branch,
            'account_name'          => $p->account_name,
            'account_number'        => $mask($p->account_number),
            'mobile_money_provider' => $p->mobile_money_provider,
            'mobile_money_number'   => $mask($p->mobile_money_number),
            'tin'                   => $p->tin,
            'tier2_scheme'          => $p->tier2_scheme,
            'tier3_scheme'          => $p->tier3_scheme,
            'tier3_percent'         => $p->tier3_percent !== null ? (float) $p->tier3_percent : null,
            'reliefs'               => $p->reliefs ?? [],
            'tax_resident'          => $p->tax_resident,
            'overtime_eligible'     => $p->overtime_eligible,
            'notes'                 => $masked ? null : $p->notes,
        ];

        return [
            'employee'     => $this->employee($employee) + [
                'ssnit_number' => $employee->ssnit_number,
                'ghana_card'   => $employee->contactDetail?->ghana_card_number,
            ],
            'profile'      => $current ? $profile($current) : null,
            // Scheduled changes (later dates) and past details.
            'history'      => $profiles->reject(fn ($p) => $p->is($current))->map($profile)->values(),
            'missing'      => $current ? $current->gaps($employee->ssnit_number, $employee->contactDetail?->ghana_card_number) : ['Pay details'],
            'components'   => $employee->payComponents()->with('component')->whereHas('component')->orderBy('effective_from')->get()->map(fn ($c) => [
                'uuid'           => $c->uuid,
                'component'      => ['uuid' => $c->component->uuid, 'name' => $c->component->name, 'kind' => $c->component->kind->value, 'rate' => $c->component->rate !== null ? (float) $c->component->rate : null, 'currency' => $c->component->currency, 'calculation' => $c->component->calculation->value, 'unit' => $c->component->unit],
                'amount'         => $c->amount !== null ? (float) $c->amount : null,
                'effective_from' => $c->effective_from->toDateString(),
                'effective_to'   => $c->effective_to?->toDateString(),
                'active'         => $c->effective_from->lte(now()) && (!$c->effective_to || $c->effective_to->gte(now()->startOfDay())),
                'notes'          => $masked ? null : $c->notes,
            ])->values(),
            'options'      => $masked ? null : [
                'base_currency'   => $this->base(),
                'payment_methods' => collect(EmployeePayProfile::PAYMENT_METHODS)->map(fn ($label, $value) => ['value' => $value, 'label' => $label])->values(),
                'components'      => PayComponent::where('active', true)->where('recurring', true)->where('is_system', false)->orderBy('kind')->orderBy('sort_order')->get()
                    ->map(fn ($c) => ['uuid' => $c->uuid, 'name' => $c->name, 'kind' => $c->kind->value, 'rate' => $c->rate !== null ? (float) $c->rate : null, 'currency' => $c->currency, 'calculation' => $c->calculation->value, 'unit' => $c->unit])->values(),
                'reliefs'         => collect(StatutoryRateSet::inForceOn(now())?->reliefs ?? [])->map(fn ($r) => ['code' => $r['code'], 'name' => $r['name'], 'max_units' => $r['max_units'] ?? null])->values(),
            ],
        ];
    }

    private function employee(Employee $e): array
    {
        return [
            'uuid'       => $e->uuid,
            'name'       => trim(preg_replace('/\s+/', ' ', $e->name)),
            'staff_id'   => $e->staff_id,
            'department' => $e->department?->name,
            'position'   => $e->jobDetail?->position?->name,
        ];
    }

    private function base(): string
    {
        return strtoupper((string) setting('payroll.base_currency', 'GHS'));
    }
}
