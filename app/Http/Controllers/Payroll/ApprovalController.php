<?php

namespace App\Http\Controllers\Payroll;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Models\Payroll\Approval;
use App\Services\Payroll\ApprovalWorkflowService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Deciding a step of any payroll approval (pay runs now; overtime and loans later). Who may decide is checked by the workflow. */
class ApprovalController extends Controller
{
    public function __construct(private readonly ApprovalWorkflowService $approvals)
    {
    }

    /**
     * The signed-in user's approvals inbox: waiting for their decision, or ones they decided.
     * Pay runs are decided on the run itself; this lists them too, linking there.
     */
    public function index(Request $request): JsonResponse
    {
        $request->validate(['tab' => ['nullable', Rule::in(['waiting', 'decided'])]]);
        $user = $request->user();
        $query = $request->input('tab', 'waiting') === 'decided'
            ? Approval::whereHas('decisions', fn ($d) => $d->where('decided_by', $user->id))->latest('updated_at')
            : $this->waiting($user)->oldest();

        $page = $query->with(['subject', 'employee', 'decisions.decider'])->paginate(min((int) $request->input('per_page', 20), 50));

        return ApiResponse::success([
            'data' => collect($page->items())->map(fn (Approval $a) => $this->inboxRow($a, $user))->values(),
            'meta' => [
                'total' => $page->total(), 'current_page' => $page->currentPage(), 'per_page' => $page->perPage(),
                'waiting_count' => $this->waiting($user)->count(),
            ],
        ]);
    }

    /** Waiting for this user, plus (for those who pick them up) steps nobody else could be found to decide. */
    private function waiting($user)
    {
        return Approval::where(fn ($q) => $q->waitingFor($user->id)->when(
            $this->approvals->canEscalate($user),
            fn ($w) => $w->orWhere(fn ($n) => $n->where('status', Approval::PENDING)
                ->where(fn ($e) => $e->whereNull('current_approver_ids')->orWhereJsonLength('current_approver_ids', 0))
                // Never their own request.
                ->where(fn ($e) => $e->whereNull('employee_id')->orWhere('employee_id', '!=', $user->employee_id ?? 0))
                ->where(fn ($e) => $e->where('process', '!=', 'pay_run')->orWhere('started_by', '!=', $user->id))),
        ));
    }

    private function inboxRow(Approval $a, $user): array
    {
        $step = $a->currentStep();
        $adjustable = $a->process->adjustable();
        $values = $a->subject instanceof \App\Contracts\ApprovalSubject ? $a->subject->adjustableValues() : [];

        return [
            'uuid'       => $a->uuid,
            'process'    => ['value' => $a->process->value, 'label' => $a->process->label()],
            'employee'   => $a->employee ? ['uuid' => $a->employee->uuid, 'name' => trim(preg_replace('/\s+/', ' ', $a->employee->name)), 'staff_id' => $a->employee->staff_id] : null,
            'summary'    => $a->subject instanceof \App\Contracts\ApprovalSubject ? $a->subject->approvalSummary() : null,
            'link'       => $a->process === \App\Enums\Payroll\ApprovalProcess::PAY_RUN && $a->subject ? "/payroll/runs/{$a->subject->uuid}" : null,
            'can_decide' => $this->approvals->canDecide($a, $user),
            // What this step may change, with the values now.
            'adjustable' => collect($step['can_adjust'] ?? [])->map(fn ($f) => ['field' => $f, 'label' => $adjustable[$f] ?? $f, 'value' => $values[$f] ?? null])->values(),
            'started_at' => $a->created_at?->toIso8601String(),
            'approval'   => self::summary($a),
        ];
    }

    public function decide(Request $request, Approval $approval): JsonResponse
    {
        $data = $request->validate([
            'decision'    => ['required', Rule::in(['approved', 'rejected'])],
            'comment'     => ['nullable', 'string', 'max:2000'],
            'adjustments' => ['nullable', 'array'],
        ]);

        $approval = $this->approvals->decide($approval, $request->user(), $data['decision'], $data['comment'] ?? null, $data['adjustments'] ?? []);

        return ApiResponse::success(self::summary($approval->load('decisions.decider')), $data['decision'] === 'approved' ? 'Approved.' : 'Rejected.');
    }

    /** The approval's steps, decisions so far, and where it stands. */
    public static function summary(Approval $a): array
    {
        $decisions = $a->decisions->keyBy('position');

        return [
            'uuid'             => $a->uuid,
            'status'           => $a->status,
            'current_position' => $a->current_position,
            // Nobody could be found to decide the current step: someone needs approve- or configure-payroll.
            'no_approver'      => $a->isPending() && empty($a->current_approver_ids),
            'steps'            => collect($a->steps)->map(function ($step) use ($a, $decisions) {
                $d = $decisions->get($step['position']);

                return [
                    'position' => $step['position'],
                    'name'     => $step['name'],
                    'state'    => $d ? $d->decision : ($a->current_position === $step['position'] ? 'current' : 'waiting'),
                    'by'       => $d?->decider?->name,
                    'at'       => $d?->created_at?->toIso8601String(),
                    'comment'  => $d?->comment,
                ];
            })->values(),
        ];
    }
}
