<?php

namespace App\Http\Controllers\Payroll;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Models\Payroll\EmployeePayProfile;
use App\Models\Payroll\Timesheet;
use App\Models\Payroll\WorkSite;
use App\Models\SelfService\Employee;
use App\Models\User;
use App\Services\Payroll\PayPeriod;
use App\Services\Payroll\TimesheetService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;

/**
 * Timesheets: the employee's own (self-service), everyone's for timekeepers and HR (`manage-timesheets`), and
 * read-only for the people approving them.
 */
class TimesheetController extends Controller
{
    public function __construct(private readonly TimesheetService $timesheets)
    {
    }

    /** The signed-in employee's sheet for a pay month (made the first time it's opened). */
    public function mine(Request $request): JsonResponse
    {
        [$year, $month] = $this->period($request);
        $sheet = $this->timesheets->open($this->me($request), $year, $month, $request->user());

        return ApiResponse::success($this->detail($sheet, $request->user()));
    }

    /** The signed-in employee's sheets for a pay month (a freelancer has one per client / work order). */
    public function myList(Request $request): JsonResponse
    {
        [$year, $month] = $this->period($request);
        $me = $this->me($request);

        return ApiResponse::success([
            'year'         => $year,
            'month'        => $month,
            'is_freelance' => $me->job_type === 'freelance',
            'timesheets'   => Timesheet::where(['employee_id' => $me->id, 'year' => $year, 'month' => $month])->with(['client', 'workOrder.client'])
                ->orderBy('template')->orderBy('id')->get()->map(fn ($t) => self::summary($t))->values(),
            'clients'      => \App\Models\Payroll\Client::where('active', true)->orderBy('name')->get(['uuid', 'name']),
            'work_orders'  => \App\Models\Payroll\WorkOrder::where('active', true)->with('client')->orderBy('number')->get()
                ->map(fn ($w) => ['uuid' => $w->uuid, 'number' => $w->number, 'description' => $w->description, 'client' => $w->client?->name])->values(),
        ]);
    }

    /** A freelancer starts a sheet for a client / work order (or opens the one they have). */
    public function storeMine(Request $request): JsonResponse
    {
        $me = $this->me($request);
        abort_unless($me->job_type === 'freelance', 422, 'Freelance timesheets are for freelancers.');

        return $this->openFreelance($request, $me);
    }

    public function detailsMine(Request $request, Timesheet $timesheet): JsonResponse
    {
        abort_unless($timesheet->employee_id === $this->me($request)->id, 404);

        return $this->details($request, $timesheet);
    }

    /** The task, and the client's sign-off (name, date, a scan of the signed sheet). */
    public function details(Request $request, Timesheet $timesheet): JsonResponse
    {
        $data = $request->validate([
            'task_description' => ['nullable', 'string', 'max:2000'],
            'client_rep_name'  => ['nullable', 'string', 'max:255'],
            'client_signed_on' => ['nullable', 'date'],
            'signoff'          => ['nullable', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:10240'],
        ]);
        $sheet = $this->timesheets->saveFreelanceDetails($timesheet, $data, $request->file('signoff'));

        return ApiResponse::success($this->detail($sheet, $request->user()), 'Details saved.');
    }

    /** A short-lived link to the client's signed scan. */
    public function signoff(Request $request, Timesheet $timesheet): JsonResponse
    {
        abort_unless($this->canView($timesheet, $request->user()) && $timesheet->client_signoff_path, 404);

        return ApiResponse::success(['url' => \Illuminate\Support\Facades\Storage::disk('s3')->temporaryUrl($timesheet->client_signoff_path, now()->addMinutes(5))]);
    }

    private function openFreelance(Request $request, Employee $employee): JsonResponse
    {
        [$year, $month] = $this->period($request);
        $data = $request->validate([
            'work_order_uuid'  => ['nullable', Rule::exists('work_orders', 'uuid')->whereNull('deleted_at')],
            'client_uuid'      => ['nullable', Rule::exists('clients', 'uuid')->whereNull('deleted_at')],
            'task_description' => ['nullable', 'string', 'max:2000'],
        ]);
        $sheet = $this->timesheets->openFreelance(
            $employee, $year, $month,
            isset($data['work_order_uuid']) ? \App\Models\Payroll\WorkOrder::where('uuid', $data['work_order_uuid'])->first() : null,
            isset($data['client_uuid']) ? \App\Models\Payroll\Client::where('uuid', $data['client_uuid'])->first() : null,
            $data['task_description'] ?? null,
            $request->user(),
        );

        return ApiResponse::success($this->detail($sheet, $request->user()), 'Timesheet opened.', 201);
    }

    public static function summary(Timesheet $t): array
    {
        return [
            'uuid' => $t->uuid, 'template' => $t->template, 'status' => $t->status, 'submitted_at' => $t->submitted_at?->toIso8601String(),
            'days_worked' => $t->totals['days_worked'] ?? null,
            'client' => ($c = $t->client ?? $t->workOrder?->client) ? $c->name : null,
            'work_order' => $t->workOrder?->number,
        ];
    }

    public function saveMine(Request $request, Timesheet $timesheet): JsonResponse
    {
        abort_unless($timesheet->employee_id === $this->me($request)->id, 404);

        return $this->save($request, $timesheet);
    }

    public function saveDayMine(Request $request, Timesheet $timesheet, string $date): JsonResponse
    {
        abort_unless($timesheet->employee_id === $this->me($request)->id, 404);

        return $this->saveDay($request, $timesheet, $date);
    }

    public function fillMine(Request $request, Timesheet $timesheet): JsonResponse
    {
        abort_unless($timesheet->employee_id === $this->me($request)->id, 404);

        return $this->fill($request, $timesheet);
    }

    public function submitMine(Request $request, Timesheet $timesheet): JsonResponse
    {
        abort_unless($timesheet->employee_id === $this->me($request)->id, 404);

        return $this->submit($request, $timesheet);
    }

    /**
     * Everyone paid for a pay month (pay details in force), with their sheet's status: who to chase. Anyone with a
     * sheet that month is listed too, even without pay details yet (flagged: their sheet can't feed pay until HR adds them).
     */
    public function index(Request $request): JsonResponse
    {
        [$year, $month] = $this->period($request);
        $data = $request->validate([
            'status' => ['nullable', Rule::in(['none', Timesheet::DRAFT, Timesheet::SUBMITTED, Timesheet::APPROVED, Timesheet::REJECTED])],
            'search' => ['nullable', 'string', 'max:100'],
        ]);
        $period = PayPeriod::forMonth($year, $month);
        $all = Timesheet::where(['year' => $year, 'month' => $month])->with(['client', 'workOrder.client'])->orderBy('id')->get()->groupBy('employee_id');
        // The status shown: the staff sheet's, or for a freelancer the least advanced of theirs.
        $order = ['none' => 0, Timesheet::REJECTED => 1, Timesheet::DRAFT => 2, Timesheet::SUBMITTED => 3, Timesheet::APPROVED => 4];
        $sheets = $all->map(fn ($list) => $list->firstWhere('template', Timesheet::STAFF) ?? $list->sortBy(fn ($t) => $order[$t->status])->first());

        $paid = EmployeePayProfile::inForceOn($period->end)->pluck('employee_id');
        $employees = Employee::query()
            ->whereIn('id', $paid->merge($all->keys())->unique())
            ->when($data['search'] ?? null, fn ($q, $v) => $q->where(fn ($w) => $w->where('first_name', 'like', "%{$v}%")->orWhere('last_name', 'like', "%{$v}%")->orWhere('staff_id', 'like', "%{$v}%")))
            ->with('department')->orderBy('first_name')->orderBy('last_name')->get();

        $paidIds = $paid->flip();
        $rows = $employees->map(function (Employee $e) use ($sheets, $all, $paidIds) {
            $sheet = $sheets->get($e->id);

            return [
                'employee'  => ['uuid' => $e->uuid, 'name' => trim(preg_replace('/\s+/', ' ', $e->name)), 'staff_id' => $e->staff_id, 'department' => $e->department?->name],
                'is_freelance' => $e->job_type === 'freelance',
                'no_pay_details' => !$paidIds->has($e->id),
                'timesheet'  => $sheet ? self::summary($sheet) : null,
                // Every sheet that month (a freelancer has one per client / work order).
                'timesheets' => ($all->get($e->id) ?? collect())->map(fn ($t) => self::summary($t))->values(),
            ];
        })->filter(fn ($r) => !($data['status'] ?? null) || ($r['timesheet']['status'] ?? 'none') === $data['status'])->values();

        return ApiResponse::success([
            'period'  => ['label' => $period->label(), 'start' => $period->start->toDateString(), 'end' => $period->end->toDateString()],
            'rows'    => $rows,
            'summary' => collect(['none', Timesheet::DRAFT, Timesheet::SUBMITTED, Timesheet::APPROVED, Timesheet::REJECTED])
                ->mapWithKeys(fn ($s) => [$s => $employees->filter(fn ($e) => ($sheets->get($e->id)?->status ?? 'none') === $s)->count()]),
        ]);
    }

    /** Open (make) an employee's sheet for a pay month, to fill in or import for them; for a freelancer, per client / work order. */
    public function store(Request $request): JsonResponse
    {
        [$year, $month] = $this->period($request);
        $employee = Employee::where('uuid', $request->validate(['employee_uuid' => ['required', 'uuid']])['employee_uuid'])->firstOrFail();
        if ($request->input('template') === Timesheet::FREELANCE) {
            return $this->openFreelance($request, $employee);
        }

        return ApiResponse::success($this->detail($this->timesheets->open($employee, $year, $month, $request->user()), $request->user()), 'Timesheet opened.', 201);
    }

    public function show(Request $request, Timesheet $timesheet): JsonResponse
    {
        abort_unless($this->canView($timesheet, $request->user()), 403, 'You can\'t see this timesheet.');

        return ApiResponse::success($this->detail($timesheet, $request->user()));
    }

    public function save(Request $request, Timesheet $timesheet): JsonResponse
    {
        $data = $request->validate([
            'days'                  => ['required', 'array', 'max:31'],
            'days.*.date'           => ['required', 'date'],
            'days.*.values'         => ['nullable', 'array'],
            'days.*.work_site_uuid' => ['nullable', 'uuid'],
            'days.*.notes'          => ['nullable', 'string', 'max:255'],
            'days.*.entries'        => ['sometimes', 'array', 'max:10'],
            ...collect(self::ENTRY_RULES)->mapWithKeys(fn ($r, $k) => ["days.*.entries.*.{$k}" => $r])->all(),
        ]);
        $sheet = $this->timesheets->save($timesheet, $data['days']);

        return ApiResponse::success($this->detail($sheet, $request->user()), 'Timesheet saved.');
    }

    private const ENTRY_RULES = [
        'uuid'           => ['nullable', 'uuid'],
        'work_site_uuid' => ['nullable', 'uuid'],
        'hours'          => ['nullable', 'numeric', 'between:0,24'],
        'start_time'     => ['nullable', 'date_format:H:i'],
        'end_time'       => ['nullable', 'date_format:H:i'],
        'break_minutes'  => ['nullable', 'integer', 'between:0,720'],
        'values'         => ['nullable', 'array'],
        'planned'        => ['nullable', 'boolean'],
        'notes'          => ['nullable', 'string', 'max:255'],
    ];

    /** One day's entries (staff sheets): what was worked at each site. */
    public function saveDay(Request $request, Timesheet $timesheet, string $date): JsonResponse
    {
        $data = $request->validate([
            'entries' => ['present', 'array', 'max:10'],
            'notes'   => ['nullable', 'string', 'max:255'],
            ...collect(self::ENTRY_RULES)->mapWithKeys(fn ($r, $k) => ["entries.*.{$k}" => $r])->all(),
        ]);
        abort_unless(preg_match('/^\d{4}-\d{2}-\d{2}$/', $date), 404);
        abort_if($timesheet->isFreelance(), 422, 'Freelance timesheets are saved by column.');
        $sheet = $this->timesheets->saveDay($timesheet, $date, $data['entries'], $data['notes'] ?? null);

        return ApiResponse::success($this->detail($sheet, $request->user()), 'Day saved.');
    }

    /** Fill every empty working day with a normal day at a site. */
    public function fill(Request $request, Timesheet $timesheet): JsonResponse
    {
        $data = $request->validate(['work_site_uuid' => ['nullable', 'uuid']]);
        $site = isset($data['work_site_uuid']) ? WorkSite::where('uuid', $data['work_site_uuid'])->where('active', true)->first() : null;
        abort_if(isset($data['work_site_uuid']) && !$site, 422, 'Choose a site from the list.');
        $count = $this->timesheets->fill($timesheet, $site);

        return ApiResponse::success($this->detail($timesheet->fresh(), $request->user()),
            $count ? "{$count} " . str('day')->plural($count) . ' filled.' : 'Every working day already has something in it.');
    }

    public function submit(Request $request, Timesheet $timesheet): JsonResponse
    {
        $sheet = $this->timesheets->submit($timesheet, $request->user());

        return ApiResponse::success($this->detail($sheet, $request->user()), 'Timesheet sent for approval.');
    }

    /** The employee takes their own sheet back to correct it, while it's waiting for approval. */
    public function withdrawMine(Request $request, Timesheet $timesheet): JsonResponse
    {
        abort_unless($timesheet->employee_id === $this->me($request)->id, 404);
        $sheet = $this->timesheets->withdraw($timesheet);

        return ApiResponse::success($this->detail($sheet, $request->user()), 'Timesheet taken back. Correct it and send it again.');
    }

    public function reopen(Request $request, Timesheet $timesheet): JsonResponse
    {
        $sheet = $this->timesheets->reopen($timesheet);

        return ApiResponse::success($this->detail($sheet, $request->user()), 'Timesheet reopened.');
    }

    /** Fill the sheet from an FM-06 Excel file (one employee's). */
    public function import(Request $request, Timesheet $timesheet): JsonResponse
    {
        $data = $request->validate([
            'file'      => ['required', 'file', 'mimes:xlsx,xls', 'max:10240'],
            'first_row' => ['nullable', 'integer', 'between:1,200'],
        ]);
        $sheet = $this->timesheets->importFm06($timesheet, $request->file('file')->getRealPath(), (int) ($data['first_row'] ?? 10));

        return ApiResponse::success($this->detail($sheet, $request->user()), 'Timesheet imported.');
    }

    /** The sheet in the FM-06 layout, with who approved it and when (e.g. for the client). */
    public function pdf(Request $request, Timesheet $timesheet): Response
    {
        abort_unless($this->canView($timesheet, $request->user()), 403);
        $letterhead = \App\Helpers\PdfLetterhead::get();
        $sheet = $this->timesheets->present($timesheet);
        $decisions = $timesheet->approval?->decisions()->with('decider')->orderBy('position')->get() ?? collect();

        return Pdf::loadView('payroll.timesheet', [
            'sheet' => $sheet, 'decisions' => $decisions, 'company' => $letterhead['company'], 'logo' => $letterhead['logo'],
        ])->setPaper('a4')->download('timesheet-' . str($sheet['employee']['name'])->slug() . "-{$timesheet->year}-" . str_pad($timesheet->month, 2, '0', STR_PAD_LEFT) . '.pdf');
    }

    private function detail(Timesheet $sheet, User $user): array
    {
        return $this->timesheets->present($sheet) + [
            'sites' => WorkSite::where('active', true)->orderBy('code')->get()->map(fn ($s) => TimesheetService::site($s))->values(),
            'can'   => [
                'edit'   => $sheet->isEditable() && ($sheet->employee_id === $user->employee_id || $user->can('manage-timesheets')),
                'reopen' => !$sheet->isEditable() && $user->can('manage-timesheets'),
                // The employee, while it's waiting: take it back to correct it.
                'withdraw' => $sheet->employee_id === $user->employee_id && $sheet->status === Timesheet::SUBMITTED && (bool) $sheet->approval?->isPending(),
                // An approver whose turn it is: approve or reject it here, having read it.
                'decide' => $sheet->approval !== null && app(\App\Services\Payroll\ApprovalWorkflowService::class)->canDecide($sheet->approval, $user),
            ],
        ];
    }

    /** The employee, timekeepers and HR, and the people deciding (or who decided) its approval. */
    private function canView(Timesheet $sheet, User $user): bool
    {
        if ($sheet->employee_id === $user->employee_id || $user->can('manage-timesheets')) {
            return true;
        }
        $approval = $sheet->approval;

        return $approval && (in_array($user->id, (array) $approval->current_approver_ids, true)
            || $approval->decisions()->where('decided_by', $user->id)->exists());
    }

    /** The pay month asked for (default: the period today falls in). */
    private function period(Request $request): array
    {
        $data = $request->validate([
            'year'  => ['nullable', 'integer', 'between:2000,2100'],
            'month' => ['nullable', 'integer', 'between:1,12'],
        ]);
        if (isset($data['year'], $data['month'])) {
            return [(int) $data['year'], (int) $data['month']];
        }
        $current = PayPeriod::containing(now()->startOfDay());

        return [$current->year, $current->month];
    }

    private function me(Request $request): Employee
    {
        $employee = Employee::find($request->user()->employee_id);
        abort_unless($employee, 404, 'Your account is not linked to an employee record.');

        return $employee;
    }
}
