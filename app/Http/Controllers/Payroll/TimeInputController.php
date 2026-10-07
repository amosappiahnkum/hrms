<?php

namespace App\Http\Controllers\Payroll;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Models\Payroll\PayComponent;
use App\Models\Payroll\TimeInput;
use App\Models\SelfService\Employee;
use App\Services\Payroll\TimeInputService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/** Monthly time inputs: a sheet per component and month, one line per employee. */
class TimeInputController extends Controller
{
    public function __construct(private readonly TimeInputService $inputs)
    {
    }

    /** Employees with what's entered for the component and month, and the totals so far. */
    public function index(Request $request): JsonResponse
    {
        $components = $this->inputs->components();
        $data = $request->validate([
            'year'               => ['required', 'integer', 'between:2000,2100'],
            'month'              => ['required', 'integer', 'between:1,12'],
            'pay_component_uuid' => ['nullable', Rule::in($components->pluck('uuid'))],
            'entered_only'       => ['nullable', 'boolean'],
        ]);
        $component = $components->firstWhere('uuid', $data['pay_component_uuid'] ?? null) ?? $components->first();
        $base = fn () => TimeInput::where(['year' => $data['year'], 'month' => $data['month'], 'pay_component_id' => $component?->id])->whereIn('status', ['pending', 'approved']);

        $page = Employee::query()
            ->when($request->search, fn ($q, $v) => $q->where(fn ($w) => $w->where('first_name', 'like', "%{$v}%")->orWhere('last_name', 'like', "%{$v}%")->orWhere('staff_id', 'like', "%{$v}%")))
            ->when($request->boolean('entered_only'), fn ($q) => $q->whereIn('id', $base()->select('employee_id')))
            ->orderBy('first_name')->orderBy('last_name')
            ->paginate(min((int) $request->input('per_page', 25), 100));
        $entries = $component ? $base()->whereIn('employee_id', collect($page->items())->pluck('id'))->with(['approval.decisions.decider', 'payRun'])->get()->keyBy('employee_id') : collect();

        return ApiResponse::success([
            'components' => $components->map(fn ($c) => ['uuid' => $c->uuid, 'code' => $c->code, 'name' => $c->name, 'unit' => $c->unit, 'rate' => (float) $c->rate, 'currency' => $c->currency])->values(),
            'component'  => $component?->uuid,
            'rows'       => collect($page->items())->map(fn (Employee $e) => [
                'employee' => ['uuid' => $e->uuid, 'name' => trim(preg_replace('/\s+/', ' ', $e->name)), 'staff_id' => $e->staff_id],
                'entry'    => ($t = $entries->get($e->id)) ? self::entry($t) : null,
            ])->values(),
            'meta'       => ['total' => $page->total(), 'current_page' => $page->currentPage(), 'per_page' => $page->perPage()],
            'summary'    => $component ? [
                'entered'  => $base()->count(),
                'quantity' => (float) $base()->sum('quantity'),
                'pending'  => $base()->where('status', 'pending')->count(),
            ] : null,
            'requires_approval' => (bool) setting('payroll.time_inputs_require_approval', true),
        ]);
    }

    /** Set one employee's quantity for a component and month (empty or 0 removes it). */
    public function save(Request $request): JsonResponse
    {
        $data = $request->validate([
            'employee_uuid'      => ['required', 'uuid'],
            'pay_component_uuid' => ['required', 'uuid'],
            'year'               => ['required', 'integer', 'between:2000,2100'],
            'month'              => ['required', 'integer', 'between:1,12'],
            'quantity'           => ['nullable', 'numeric', 'min:0', 'max:10000'],
            'notes'              => ['nullable', 'string', 'max:500'],
        ]);
        $employee = Employee::where('uuid', $data['employee_uuid'])->firstOrFail();
        $component = PayComponent::where('uuid', $data['pay_component_uuid'])->firstOrFail();

        $input = $this->inputs->save($employee, $component, $data['year'], $data['month'], isset($data['quantity']) ? (float) $data['quantity'] : null, $data['notes'] ?? null, $request->user());

        return ApiResponse::success($input ? self::entry($input->load(['approval.decisions.decider', 'payRun'])) : null, $input ? 'Saved.' : 'Removed.');
    }

    /** The month's sheet: every employee by every per-unit component, filled with what's entered. */
    public function template(Request $request): BinaryFileResponse
    {
        $data = $request->validate(['year' => ['required', 'integer', 'between:2000,2100'], 'month' => ['required', 'integer', 'between:1,12']]);

        return response()->download($this->inputs->template((int) $data['year'], (int) $data['month']), sprintf('time-inputs-%04d-%02d.xlsx', $data['year'], $data['month']))->deleteFileAfterSend();
    }

    public function import(Request $request): JsonResponse
    {
        $data = $request->validate([
            'year'  => ['required', 'integer', 'between:2000,2100'],
            'month' => ['required', 'integer', 'between:1,12'],
            'file'  => ['required', 'file', 'mimes:xlsx,xls', 'max:10240'],
            'check' => ['boolean'],
        ]);
        // check: work everything out and say what would change, saving nothing.
        if ($request->boolean('check')) {
            return ApiResponse::success($this->inputs->importGrid((int) $data['year'], (int) $data['month'], $request->file('file')->getRealPath(), $request->user(), apply: false), 'Checked.');
        }
        $result = $this->inputs->importGrid((int) $data['year'], (int) $data['month'], $request->file('file')->getRealPath(), $request->user());

        if ($result['errors']) {
            return ApiResponse::error('Nothing was imported: fix these rows and try again.', ['rows' => $result['errors']], 422);
        }

        return ApiResponse::success($result, $result['saved'] + $result['removed']
            ? "Saved {$result['saved']}, removed {$result['removed']}."
            : 'Nothing to change: the sheet matches what is entered.');
    }

    public static function entry(TimeInput $t): array
    {
        return [
            'uuid'     => $t->uuid,
            'quantity' => (float) $t->quantity,
            'notes'    => $t->notes,
            'status'   => $t->status,
            'paid_in'  => $t->payRun ? ['uuid' => $t->payRun->uuid, 'name' => $t->payRun->name] : null,
            'approval' => $t->approval ? ApprovalController::summary($t->approval) : null,
        ];
    }
}
