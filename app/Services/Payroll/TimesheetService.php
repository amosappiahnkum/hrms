<?php

namespace App\Services\Payroll;

use App\Enums\Payroll\ApprovalProcess;
use App\Exceptions\UserFacingException;
use App\Models\LeaveRequest;
use App\Models\Payroll\Approval;
use App\Models\Payroll\Client;
use App\Models\Payroll\WorkOrder;
use App\Models\Payroll\OvertimeRequest;
use App\Models\Payroll\PayComponent;
use App\Models\Payroll\PayRun;
use App\Models\Payroll\Timesheet;
use App\Models\Payroll\TimesheetDay;
use App\Models\Payroll\TimesheetEntry;
use App\Models\Payroll\TimeInput;
use App\Models\Payroll\WorkSite;
use App\Models\SelfService\Employee;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\IOFactory;

/**
 * Daily timesheets (FM-06): a row per day of the pay period, with the organization's columns (setting
 * `payroll.timesheet_columns`). Filled in by the employee (or a timekeeper), approved through the timesheet
 * workflow, and then, when pay comes from timesheets (`payroll.time_source`), they feed the time inputs, days
 * worked and overtime hours.
 *
 * On staff sheets a day is filled with entries (one per site): hours or times, and ticks. The day's normal and
 * overtime hours are worked out from them (`split()`, by the working day), and overtime approved in advance shows
 * up as a planned entry to confirm. Freelance sheets are filled by column, a row per day.
 */
class TimesheetService
{
    /** Ticks in a sheet: anything that means "yes". */
    private const TICKS = ['√', '✓', '✔', 'v', 'x', 'yes', 'y', '1', 'true'];

    public function __construct(
        private readonly ApprovalWorkflowService $approvals,
        private readonly OvertimeService $overtime,
        private readonly TimeInputService $timeInputs,
    ) {
    }

    /**
     * The columns, as configured: key, label, type (hours | check), what it feeds (overtime by `day_kind`, a
     * `time_input` per-unit `component`, or nothing), whether it needs an offshore site, and its FM-06 column
     * letter for imports.
     *
     * @return array<int, array{key: string, label: string, type: string, feeds: ?string, day_kind: ?string, component: ?string, requires_offshore: bool, sheet_column: ?string}>
     */
    public function columns(string $template = Timesheet::STAFF): array
    {
        $setting = $template === Timesheet::FREELANCE ? 'payroll.freelance_timesheet_columns' : 'payroll.timesheet_columns';

        return collect((array) setting($setting, []))
            ->filter(fn ($c) => is_array($c) && filled($c['key'] ?? null) && filled($c['label'] ?? null))
            ->map(fn ($c) => [
                'key'               => (string) $c['key'],
                'label'             => (string) $c['label'],
                'type'              => ($c['type'] ?? 'hours') === 'check' ? 'check' : 'hours',
                'feeds'             => in_array($c['feeds'] ?? null, ['overtime', 'time_input'], true) ? $c['feeds'] : null,
                'day_kind'          => in_array($c['day_kind'] ?? null, ['weekday', 'weekend', 'holiday'], true) ? $c['day_kind'] : null,
                'component'         => filled($c['component'] ?? null) ? strtoupper((string) $c['component']) : null,
                'requires_offshore' => (bool) ($c['requires_offshore'] ?? false),
                'normal'            => (bool) ($c['normal'] ?? false),
                'sheet_column'      => filled($c['sheet_column'] ?? null) ? strtoupper((string) $c['sheet_column']) : null,
            ])->unique('key')->values()->all();
    }

    /** The column normal hours go in: the one marked `normal`, else the first hours column that feeds nothing. */
    private function normalKey(array $columns): ?string
    {
        $hours = collect($columns)->where('type', 'hours');

        return ($hours->firstWhere('normal', true) ?? $hours->first(fn ($c) => !$c['feeds']))['key'] ?? null;
    }

    /** The overtime column for a kind of day: the one for that kind, else one for any day. */
    private function overtimeKey(array $columns, string $kind): ?string
    {
        $overtime = collect($columns)->where('type', 'hours')->where('feeds', 'overtime');

        return ($overtime->firstWhere('day_kind', $kind) ?? $overtime->first(fn ($c) => !$c['day_kind']))['key'] ?? null;
    }

    /** Staff sheets: the columns worked out from the entries (normal and overtime hours), not filled in. */
    private function computedKeys(array $columns): array
    {
        return array_values(array_filter([
            $this->normalKey($columns),
            ...collect($columns)->where('type', 'hours')->where('feeds', 'overtime')->pluck('key')->all(),
        ]));
    }

    /** The employee's sheet for a pay month, made with a row per day of the pay period the first time. */
    public function open(Employee $employee, int $year, int $month, ?User $user): Timesheet
    {
        $sheet = Timesheet::where(['employee_id' => $employee->id, 'year' => $year, 'month' => $month, 'scope' => Timesheet::STAFF])->first()
            ?? $this->make($employee, $year, $month, ['template' => Timesheet::STAFF, 'scope' => Timesheet::STAFF], $user);
        if ($sheet->isEditable()) {
            $this->markLeave($sheet);
        }

        return $sheet->load('days');
    }

    /**
     * A freelancer's sheet for a pay month and a client / work order (one each, as the freelance form asks).
     * With a work order, its client is the sheet's client.
     */
    public function openFreelance(Employee $employee, int $year, int $month, ?WorkOrder $order, ?Client $client, ?string $task, ?User $user): Timesheet
    {
        $client = $order?->client ?? $client;
        $scope = $order ? "wo:{$order->id}" : ($client ? "client:{$client->id}" : Timesheet::FREELANCE);
        $sheet = Timesheet::where(['employee_id' => $employee->id, 'year' => $year, 'month' => $month, 'scope' => $scope])->first()
            ?? $this->make($employee, $year, $month, [
                'template' => Timesheet::FREELANCE, 'scope' => $scope, 'work_order_id' => $order?->id, 'client_id' => $client?->id,
                'task_description' => $task ?: $order?->description,
            ], $user);

        return $sheet->load('days');
    }

    /** A new sheet with a row per day of the pay period. */
    private function make(Employee $employee, int $year, int $month, array $attributes, ?User $user): Timesheet
    {
        $period = PayPeriod::forMonth($year, $month);

        return DB::transaction(function () use ($employee, $year, $month, $period, $attributes, $user) {
            $sheet = Timesheet::create($attributes + [
                'employee_id' => $employee->id, 'year' => $year, 'month' => $month,
                'period_start' => $period->start->toDateString(), 'period_end' => $period->end->toDateString(),
                'status' => Timesheet::DRAFT, 'created_by' => $user?->id,
            ]);
            for ($day = $period->start->copy(); $day->lte($period->end); $day->addDay()) {
                $sheet->days()->create(['date' => $day->toDateString(), 'values' => []]);
            }

            return $sheet;
        });
    }

    /**
     * A freelance sheet's details: the task, and who signed it for the client (name, date, and a scan of the
     * signed sheet when there is one).
     */
    public function saveFreelanceDetails(Timesheet $sheet, array $data, ?\Illuminate\Http\UploadedFile $scan): Timesheet
    {
        if (!$sheet->isFreelance()) {
            throw new UserFacingException('Only freelance timesheets have client details.');
        }
        if (!$sheet->isEditable()) {
            throw new UserFacingException('This timesheet has been sent for approval, so it can\'t change.');
        }
        $values = collect($data)->only(['task_description', 'client_rep_name', 'client_signed_on'])->all();
        if ($scan) {
            if ($sheet->client_signoff_path) {
                \Illuminate\Support\Facades\Storage::disk('s3')->delete($sheet->client_signoff_path);
            }
            $values['client_signoff_path'] = $scan->store("timesheet-signoffs/{$sheet->uuid}", 's3');
        }
        $sheet->update($values);

        return $sheet->fresh();
    }

    /** Leave days come from approved leave (they can't be typed in). */
    private function markLeave(Timesheet $sheet): void
    {
        $leave = LeaveRequest::where('employee_id', $sheet->employee_id)->where('status', 'hr_approved')
            ->whereDate('start_date', '<=', $sheet->period_end)->whereDate('end_date', '>=', $sheet->period_start)
            ->get(['id', 'start_date', 'end_date']);

        foreach ($sheet->days()->get() as $day) {
            $on = $leave->first(fn ($l) => $day->date->betweenIncluded(Carbon::parse($l->start_date)->startOfDay(), Carbon::parse($l->end_date)->startOfDay()));
            if ($day->is_leave !== (bool) $on || $day->leave_request_id !== $on?->id) {
                $day->update(['is_leave' => (bool) $on, 'leave_request_id' => $on?->id]);
            }
        }
    }

    /** The sheet as screens show it: its columns, each day (kind of day, holiday, leave, entries), totals and approval. */
    public function present(Timesheet $sheet): array
    {
        $sheet->loadMissing(['days.workSite', 'days.entries.workSite', 'days.entries.overtimeRequest', 'employee.department', 'approval.decisions.decider', 'client', 'workOrder.client']);
        $period = PayPeriod::ofSheet($sheet);
        $holidays = $period->holidays();
        $columns = $this->columns($sheet->template);
        $computed = $sheet->isFreelance() ? [] : $this->computedKeys($columns);
        $approvedOn = $sheet->isFreelance() ? collect() : $this->approvedOvertimeDates($sheet);

        return [
            'uuid'             => $sheet->uuid,
            'employee'         => [
                'uuid' => $sheet->employee?->uuid, 'name' => trim(preg_replace('/\s+/', ' ', (string) $sheet->employee?->name)),
                'staff_id' => $sheet->employee?->staff_id, 'department' => $sheet->employee?->department?->name,
            ],
            'year'             => $sheet->year,
            'month'            => $sheet->month,
            'label'            => $sheet->label(),
            'period_start'     => $sheet->period_start->toDateString(),
            'period_end'       => $sheet->period_end->toDateString(),
            'template'         => $sheet->template,
            // Freelance: the client and work order it's for, the task, and the client's sign-off.
            'client'           => ($c = $sheet->client ?? $sheet->workOrder?->client) ? ['uuid' => $c->uuid, 'name' => $c->name] : null,
            'work_order'       => $sheet->workOrder ? ['uuid' => $sheet->workOrder->uuid, 'number' => $sheet->workOrder->number] : null,
            'task_description' => $sheet->task_description,
            'client_rep_name'  => $sheet->client_rep_name,
            'client_signed_on' => $sheet->client_signed_on?->toDateString(),
            'has_signoff_scan' => (bool) $sheet->client_signoff_path,
            'status'           => $sheet->status,
            'editable'         => $sheet->isEditable(),
            'submitted_at'     => $sheet->submitted_at?->toIso8601String(),
            'rejected_at'      => $sheet->rejected_at?->toIso8601String(),
            'rejection_reason' => $sheet->rejection_reason,
            'approved_at'      => $sheet->approved_at?->toIso8601String(),
            // Staff sheets are filled with entries; the computed columns are worked out from them.
            'uses_entries'     => !$sheet->isFreelance(),
            'columns'          => collect($columns)->map(fn ($c) => $c + ['computed' => in_array($c['key'], $computed, true)])->all(),
            'work_day'         => [
                'start' => sprintf('%02d:%02d', intdiv(WorkingDay::start(), 60), WorkingDay::start() % 60),
                'end' => sprintf('%02d:%02d', intdiv(WorkingDay::end(), 60), WorkingDay::end() % 60),
                'break_minutes' => WorkingDay::breakMinutes(), 'hours' => WorkingDay::hours(),
            ],
            // Kinds of day whose overtime needs a request approved before the work.
            'advance_kinds'    => $this->overtime->inAdvance() ? WorkingDay::advanceKinds() : [],
            'days'             => $sheet->days->map(function (TimesheetDay $d) use ($period, $holidays, $sheet, $columns, $approvedOn) {
                $kind = $period->dayKind($d->date);
                $row = [
                    'date'      => $d->date->toDateString(),
                    'kind'      => $kind,
                    'holiday'   => $holidays[$d->date->toDateString()] ?? null,
                    'values'    => (object) ($d->values ?? []),
                    'work_site' => $d->workSite ? self::site($d->workSite) : null,
                    'is_leave'  => $d->is_leave,
                    'notes'     => $d->notes,
                ];
                if ($sheet->isFreelance()) {
                    return $row;
                }
                $entries = $d->entries->map(fn (TimesheetEntry $e) => $this->entryData($e))->all();
                $split = $this->split($kind, $entries);
                $unlinked = $split['overtime'] - $split['linked_overtime'];

                return $row + [
                    'entries'       => $d->entries->values()->map(fn (TimesheetEntry $e, $i) => [
                        'uuid'          => $e->uuid,
                        'work_site'     => $e->workSite ? self::site($e->workSite) : null,
                        'hours'         => (float) $e->hours,
                        'start_time'    => $e->startsAt(),
                        'end_time'      => $e->endsAt(),
                        'break_minutes' => $e->break_minutes,
                        'values'        => (object) ($e->values ?? []),
                        'planned'       => $e->planned,
                        'overtime_request' => $e->overtimeRequest ? [
                            'uuid' => $e->overtimeRequest->uuid, 'status' => $e->overtimeRequest->status,
                            'approved_hours' => (float) $e->overtimeRequest->approved_hours,
                        ] : null,
                        'normal_hours'   => $split['entries'][$i]['normal'] ?? 0.0,
                        'overtime_hours' => $split['entries'][$i]['overtime'] ?? 0.0,
                        'notes'          => $e->notes,
                    ])->all(),
                    'normal_hours'   => $split['normal'],
                    'overtime_hours' => $split['overtime'],
                    // Overtime on a day that needs it approved first, with no approved request: ask for one.
                    'needs_request'  => $unlinked > 0 && $this->overtime->needsAdvance($kind) && !$approvedOn->has($d->date->toDateString()),
                    'has_planned'    => $d->entries->contains('planned', true),
                ];
            })->values(),
            'totals'           => $sheet->totals ?? $this->totals($sheet),
            'approval'         => $sheet->approval ? \App\Http\Controllers\Payroll\ApprovalController::summary($sheet->approval) : null,
        ];
    }

    public static function site(WorkSite $s): array
    {
        return ['uuid' => $s->uuid, 'code' => $s->code, 'name' => $s->name, 'is_offshore' => $s->is_offshore, 'needs_review' => (bool) $s->needs_review];
    }

    /** Dates in the sheet's period with approved overtime for the employee. */
    private function approvedOvertimeDates(Timesheet $sheet): Collection
    {
        return OvertimeRequest::where('employee_id', $sheet->employee_id)->where('status', 'approved')
            ->whereDate('work_date', '>=', $sheet->period_start)->whereDate('work_date', '<=', $sheet->period_end)
            ->pluck('work_date')->mapWithKeys(fn ($d) => [Carbon::parse($d)->toDateString() => true]);
    }

    /** An entry as `split()` and the rules read it. */
    private function entryData(TimesheetEntry $e): array
    {
        return [
            'hours' => (float) $e->hours, 'start' => WorkingDay::minutes($e->start_time), 'end' => WorkingDay::minutes($e->end_time),
            'break' => (int) ($e->break_minutes ?? 0), 'linked' => (bool) $e->overtime_request_id, 'planned' => $e->planned,
            'sort' => $e->sort, 'values' => $e->values ?? [], 'site' => $e->workSite,
        ];
    }

    /**
     * A day's normal and overtime hours from its entries (planned ones aside). On a weekday the first normal day
     * of hours (`WorkingDay::hours()`) is normal and the rest overtime; with times, hours outside the working day
     * are overtime too. On weekends and holidays every hour is overtime. Entries for approved overtime are
     * overtime. Hours are taken in time order (else as entered), so overtime lands on the later entry.
     *
     * @param array<int, array{hours: float, start: ?int, end: ?int, break: int, linked: bool, planned: bool, sort: int}> $entries
     * @return array{normal: float, overtime: float, linked_overtime: float, entries: array<int, array{normal: float, overtime: float}>}
     */
    public function split(string $kind, array $entries): array
    {
        $capacity = $kind === 'weekday' ? WorkingDay::hours() : 0.0;
        $window = [WorkingDay::start(), WorkingDay::end()];
        $order = collect($entries)->reject(fn ($e) => $e['planned'])
            ->sortBy(fn ($e, $i) => [$e['start'] ?? 100000, $e['sort'], $i]);
        $out = ['normal' => 0.0, 'overtime' => 0.0, 'linked_overtime' => 0.0, 'entries' => []];

        foreach ($order as $i => $e) {
            $hours = (float) $e['hours'];
            if ($e['linked']) {
                $normal = 0.0;
            } elseif ($e['start'] !== null && $e['end'] !== null) {
                $end = $e['end'] <= $e['start'] ? $e['end'] + 1440 : $e['end'];
                $inside = max(0, min($end, $window[1]) - max($e['start'], $window[0])) / 60;
                // The break is taken in the working day.
                $inside = max(0.0, $inside - $e['break'] / 60);
                $normal = min($inside, $capacity, $hours);
            } else {
                $normal = min($hours, $capacity);
            }
            $capacity -= $normal;
            $overtime = round($hours - $normal, 2);
            $out['entries'][$i] = ['normal' => round($normal, 2), 'overtime' => $overtime];
            $out['normal'] += $normal;
            $out['overtime'] += $overtime;
            if ($e['linked']) {
                $out['linked_overtime'] += $overtime;
            }
        }
        foreach (['normal', 'overtime', 'linked_overtime'] as $k) {
            $out[$k] = round($out[$k], 2);
        }

        return $out;
    }

    /**
     * Save days (only those sent). Staff sheets: each day's entries (`entries`), or for imports and older
     * clients the day's values by column (`values`), made into one entry. Freelance sheets: values. All or
     * nothing; the problems are listed.
     *
     * @param array<int, array{date: string, entries?: array, values?: array, work_site_uuid?: ?string, notes?: ?string}> $days
     */
    public function save(Timesheet $sheet, array $days): Timesheet
    {
        $this->assertEditable($sheet);
        if ($sheet->isFreelance()) {
            return $this->saveValues($sheet, $days);
        }

        $existing = $sheet->days()->with('entries.workSite')->get()->keyBy(fn ($d) => $d->date->toDateString());
        $siteUuids = collect($days)->flatMap(fn ($d) => [$d['work_site_uuid'] ?? null, ...collect($d['entries'] ?? [])->pluck('work_site_uuid')->all()])->filter();
        $sites = WorkSite::whereIn('uuid', $siteUuids)->get()->keyBy('uuid');
        $columns = $this->columns($sheet->template);
        $period = PayPeriod::ofSheet($sheet);
        $plans = [];
        $problems = [];

        foreach ($days as $given) {
            $date = Carbon::parse($given['date'] ?? '')->startOfDay();
            $day = $existing->get($date->toDateString());
            if (!$day) {
                $problems[] = $date->format('j M') . ' isn\'t in this timesheet\'s period.';
                continue;
            }
            $entries = array_key_exists('entries', $given) ? (array) $given['entries'] : $this->fromValues($day, $given, $columns);
            [$plan, $dayProblems] = $this->planDay($day, $period->dayKind($date), $entries, $sites, $columns);
            $plans[] = [$day, $plan, array_key_exists('notes', $given) ? $given['notes'] : $day->notes];
            $problems = array_merge($problems, $dayProblems);
        }
        if ($problems) {
            throw new UserFacingException(implode(' ', array_unique($problems)));
        }

        DB::transaction(function () use ($sheet, $plans, $period, $columns) {
            foreach ($plans as [$day, $plan, $notes]) {
                $keep = [];
                foreach ($plan as $sort => $attributes) {
                    $entry = $attributes['entry'] ?? null;
                    unset($attributes['entry']);
                    $attributes['sort'] = $sort;
                    if ($entry) {
                        $entry->update($attributes);
                    } else {
                        $entry = $day->entries()->create($attributes);
                    }
                    $keep[] = $entry->id;
                }
                $day->entries()->whereNotIn('id', $keep)->get()->each->delete();
                $day->notes = filled($notes) ? mb_substr((string) $notes, 0, 255) : null;
                $this->recompute($day, $period->dayKind($day->date), $columns);
            }
            $sheet->update(['totals' => $this->totals($sheet->fresh('days'))]);
        });

        return $sheet->fresh('days');
    }

    /** One day's entries, from the day screen. */
    public function saveDay(Timesheet $sheet, string $date, array $entries, ?string $notes): Timesheet
    {
        return $this->save($sheet, [['date' => $date, 'entries' => $entries, 'notes' => $notes]]);
    }

    /**
     * Fill every empty working day (a weekday, not leave, with nothing entered) with a normal day at a site.
     *
     * @return int how many days were filled
     */
    public function fill(Timesheet $sheet, ?WorkSite $site): int
    {
        $this->assertEditable($sheet);
        if ($sheet->isFreelance()) {
            throw new UserFacingException('Freelance timesheets are filled in day by day.');
        }
        $period = PayPeriod::ofSheet($sheet);
        $days = $sheet->days()->withCount('entries')->get()
            ->filter(fn ($d) => !$d->is_leave && $d->entries_count === 0 && empty($d->values) && $period->dayKind($d->date) === 'weekday');
        if ($days->isEmpty()) {
            return 0;
        }
        $hours = WorkingDay::hours();
        $this->save($sheet, $days->map(fn ($d) => [
            'date' => $d->date->toDateString(), 'notes' => $d->notes,
            'entries' => [['work_site_uuid' => $site?->uuid, 'hours' => $hours]],
        ])->values()->all());

        return $days->count();
    }

    /**
     * Overtime approved in advance shows up on the employee's timesheet for its pay month, ready to confirm: as
     * a planned entry at the request's site. On a weekend or holiday an entry already there (all overtime) is
     * linked instead. Skipped when the sheet has been sent or the day is leave.
     */
    public function planOvertime(OvertimeRequest $request): void
    {
        if (!feature('payroll.timesheets') || $request->status !== 'approved' || !$request->requested_in_advance
            || TimesheetEntry::where('overtime_request_id', $request->id)->exists()) {
            return;
        }
        $employee = Employee::find($request->employee_id);
        if (!$employee || $employee->job_type === 'freelance') {
            return;
        }
        $period = PayPeriod::containing($request->work_date->copy()->startOfDay());
        $sheet = $this->open($employee, $period->year, $period->month, null);
        $day = $sheet->days->first(fn ($d) => $d->date->isSameDay($request->work_date));
        if (!$sheet->isEditable() || !$day || $day->is_leave) {
            return;
        }

        DB::transaction(function () use ($sheet, $day, $request) {
            $kind = PayPeriod::ofSheet($sheet)->dayKind($day->date);
            $unlinked = $day->entries()->whereNull('overtime_request_id')->where('planned', false)->first();
            if ($kind !== 'weekday' && $unlinked) {
                $unlinked->update(['overtime_request_id' => $request->id]);
            } else {
                $day->entries()->create([
                    'work_site_id' => $request->work_site_id, 'hours' => (float) $request->approved_hours,
                    'overtime_request_id' => $request->id, 'planned' => true, 'sort' => $day->entries()->count(),
                    'notes' => mb_substr((string) ($request->activity_description ?: $request->reason), 0, 255) ?: null,
                ]);
            }
            $this->recompute($day, $kind, $this->columns($sheet->template));
            $sheet->update(['totals' => $this->totals($sheet->fresh('days'))]);
        });
    }

    private function assertEditable(Timesheet $sheet): void
    {
        if (!$sheet->isEditable()) {
            throw new UserFacingException($sheet->status === Timesheet::SUBMITTED
                ? 'This timesheet has been sent for approval, so it can\'t change.'
                : 'This timesheet has been approved, so it can\'t change.');
        }
    }

    /**
     * A day given by column (an import, or a sheet saved the old way) as entries: one entry with the normal and
     * overtime hours added up and the other columns as its values. Entries for approved overtime stay.
     */
    private function fromValues(TimesheetDay $day, array $given, array $columns): array
    {
        $computed = $this->computedKeys($columns);
        $values = $given['values'] ?? [];
        $hours = collect($computed)->sum(fn ($k) => is_numeric($values[$k] ?? null) ? (float) $values[$k] : 0);
        $other = collect($values)->except($computed)->all();
        $linked = $day->entries->filter(fn ($e) => $e->overtime_request_id)->map(fn ($e) => ['uuid' => $e->uuid, 'planned' => $e->planned] + $this->entryInput($e))->values()->all();
        if ($hours <= 0 && !array_filter($other)) {
            return $linked;
        }

        return [['hours' => round($hours, 2), 'values' => $other, 'work_site_uuid' => $given['work_site_uuid'] ?? null], ...$linked];
    }

    /** An entry as the screens send it back. */
    private function entryInput(TimesheetEntry $e): array
    {
        return [
            'work_site_uuid' => $e->workSite?->uuid, 'hours' => (float) $e->hours, 'start_time' => $e->startsAt(), 'end_time' => $e->endsAt(),
            'break_minutes' => $e->break_minutes, 'values' => $e->values ?? [], 'notes' => $e->notes,
        ];
    }

    /**
     * Check a day's entries and turn them into what's stored. An existing entry is matched by uuid; the link to
     * an overtime request can't be set or moved from here, and a planned entry stays planned until it is sent
     * with `planned: false` (confirmed).
     *
     * @return array{0: array<int, array>, 1: string[]} the entries' attributes, and the problems
     */
    private function planDay(TimesheetDay $day, string $kind, array $entries, Collection $sites, array $columns): array
    {
        $on = $day->date->format('j M');
        $problems = [];
        $plan = [];
        $byUuid = $day->entries->keyBy('uuid');
        $byKey = collect($columns)->keyBy('key');
        $computed = $this->computedKeys($columns);

        if (count($entries) > 10) {
            return [[], ["{$on}: no more than 10 entries a day."]];
        }
        if ($day->is_leave && $entries) {
            return [[], ["{$on} is leave, so nothing can be entered."]];
        }

        foreach (array_values($entries) as $given) {
            $entry = filled($given['uuid'] ?? null) ? $byUuid->get($given['uuid']) : null;
            if (filled($given['uuid'] ?? null) && !$entry) {
                $problems[] = "{$on}: an entry has been changed elsewhere; reload the timesheet.";
                continue;
            }
            $siteUuid = $given['work_site_uuid'] ?? null;
            $site = $siteUuid ? $sites->get($siteUuid) : null;
            if ($siteUuid && !$site) {
                $problems[] = "{$on}: choose a site from the list.";
            }

            $start = WorkingDay::minutes($given['start_time'] ?? null);
            $end = WorkingDay::minutes($given['end_time'] ?? null);
            $break = max(0, (int) ($given['break_minutes'] ?? 0));
            $timed = filled($given['start_time'] ?? null) || filled($given['end_time'] ?? null);
            if ($timed && ($start === null || $end === null)) {
                $problems[] = "{$on}: give both a start and an end time (e.g. 08:00 and 17:00), or neither.";
                continue;
            }
            if ($start !== null) {
                $length = ($end <= $start ? $end + 1440 : $end) - $start;
                if ($break >= $length) {
                    $problems[] = "{$on}: the break is longer than the time worked.";
                    continue;
                }
                $hours = round(($length - $break) / 60, 2);
            } else {
                $hours = is_numeric($given['hours'] ?? null) ? round((float) $given['hours'], 2) : 0.0;
            }
            $values = $this->clean(collect($given['values'] ?? [])->except($computed)->all(), $byKey);
            if ($hours <= 0 && !$values) {
                continue; // nothing in it
            }
            if ($hours < 0 || $hours > 24) {
                $problems[] = "{$on}: hours must be 0 to 24.";
            }
            foreach ($values as $key => $value) {
                $column = $byKey[$key];
                if ($column['type'] === 'hours' && (!is_numeric($value) || $value < 0 || $value > 24)) {
                    $problems[] = "{$on}: {$column['label']} must be 0 to 24 hours.";
                }
                if ($column['requires_offshore'] && !$site?->is_offshore) {
                    $problems[] = "{$on}: {$column['label']} needs an offshore site.";
                }
            }

            $plan[] = [
                'entry'               => $entry,
                'work_site_id'        => $site?->id,
                'hours'               => $hours,
                'start_time'          => $start !== null ? sprintf('%02d:%02d', intdiv($start, 60), $start % 60) : null,
                'end_time'            => $end !== null ? sprintf('%02d:%02d', intdiv($end, 60), $end % 60) : null,
                'break_minutes'       => $start !== null ? $break : null,
                'values'              => $values ?: null,
                'overtime_request_id' => $entry?->overtime_request_id,
                'planned'             => (bool) $entry?->planned && ($given['planned'] ?? true) !== false,
                'notes'               => filled($given['notes'] ?? null) ? mb_substr((string) $given['notes'], 0, 255) : null,
            ];
        }

        $data = array_map(fn ($a) => [
            'hours' => $a['hours'], 'start' => WorkingDay::minutes($a['start_time']), 'end' => WorkingDay::minutes($a['end_time']),
            'break' => (int) $a['break_minutes'], 'linked' => (bool) $a['overtime_request_id'], 'planned' => $a['planned'], 'sort' => 0,
        ], $plan);
        $worked = array_filter($data, fn ($e) => !$e['planned']);
        if (array_sum(array_column($worked, 'hours')) > 24) {
            $problems[] = "{$on}: more than 24 hours.";
        }
        $timed = array_values(array_filter($worked, fn ($e) => $e['start'] !== null));
        usort($timed, fn ($a, $b) => $a['start'] <=> $b['start']);
        for ($i = 1; $i < count($timed); $i++) {
            $prevEnd = $timed[$i - 1]['end'] <= $timed[$i - 1]['start'] ? $timed[$i - 1]['end'] + 1440 : $timed[$i - 1]['end'];
            if ($timed[$i]['start'] < $prevEnd) {
                $problems[] = "{$on}: two entries' times overlap.";
                break;
            }
        }
        if ($this->split($kind, $data)['overtime'] > 0 && !$this->overtimeKey($columns, $kind)) {
            $problems[] = "{$on}: there's no overtime column for {$kind}s. Ask HR to add one in Payroll → Settings → Timesheets.";
        }

        return [$plan, $problems];
    }

    /** A staff day's values (normal and overtime hours, and each other column) and site, from its entries. */
    private function recompute(TimesheetDay $day, string $kind, array $columns): void
    {
        $entries = $day->entries()->with('workSite')->get();
        $data = $entries->map(fn ($e) => $this->entryData($e))->all();
        $split = $this->split($kind, $data);
        $byKey = collect($columns)->keyBy('key');
        $values = [];
        foreach ($entries->reject(fn ($e) => $e->planned) as $e) {
            foreach ($e->values ?? [] as $key => $value) {
                $column = $byKey->get($key);
                if (!$column) {
                    continue;
                }
                // A tick counts once a day; other hours add up.
                $values[$key] = $column['type'] === 'check' ? true : round((float) ($values[$key] ?? 0) + (float) $value, 2);
            }
        }
        if ($split['normal'] > 0 && ($key = $this->normalKey($columns))) {
            $values[$key] = $split['normal'];
        }
        if ($split['overtime'] > 0 && ($key = $this->overtimeKey($columns, $kind))) {
            $values[$key] = round((float) ($values[$key] ?? 0) + $split['overtime'], 2);
        }
        $day->update(['values' => $values, 'work_site_id' => $entries->reject(fn ($e) => $e->planned)->first()?->work_site_id]);
    }

    /** Freelance sheets: days by column, with a site. */
    private function saveValues(Timesheet $sheet, array $days): Timesheet
    {
        $existing = $sheet->days()->get()->keyBy(fn ($d) => $d->date->toDateString());
        $sites = WorkSite::whereIn('uuid', collect($days)->pluck('work_site_uuid')->filter())->get()->keyBy('uuid');
        $columns = collect($this->columns($sheet->template))->keyBy('key');
        $changes = [];
        $problems = [];

        foreach ($days as $given) {
            $date = Carbon::parse($given['date'] ?? '')->startOfDay();
            $day = $existing->get($date->toDateString());
            if (!$day) {
                $problems[] = $date->format('j M') . ' isn\'t in this timesheet\'s period.';
                continue;
            }
            $siteUuid = $given['work_site_uuid'] ?? null;
            $site = $siteUuid ? $sites->get($siteUuid) : null;
            if ($siteUuid && !$site) {
                $problems[] = $date->format('j M') . ': choose a site from the list.';
            }
            $values = $this->clean($given['values'] ?? [], $columns);
            $changes[] = [$day, $values, $site, $given['notes'] ?? null];
        }

        $problems = array_merge($problems, ...array_map(fn ($c) => $this->valueProblems($sheet, $c[0]->date, $c[1], $c[2], $columns), $changes));
        if ($problems) {
            throw new UserFacingException(implode(' ', array_unique($problems)));
        }

        DB::transaction(function () use ($sheet, $changes) {
            foreach ($changes as [$day, $values, $site, $notes]) {
                $day->update(['values' => $values, 'work_site_id' => $site?->id, 'notes' => filled($notes) ? mb_substr((string) $notes, 0, 255) : null]);
            }
            $sheet->update(['totals' => $this->totals($sheet->fresh('days'))]);
        });

        return $sheet->fresh('days');
    }

    /** Values as stored: known columns only; hours as numbers, ticks as true; empty ones dropped. */
    private function clean(array $values, Collection $columns): array
    {
        $out = [];
        foreach ($values as $key => $value) {
            $column = $columns->get($key);
            if (!$column || $value === null || $value === '' || $value === false || $value === 0 || $value === '0') {
                continue;
            }
            $out[$key] = $column['type'] === 'check' ? true : (is_numeric($value) ? round((float) $value, 2) : $value);
        }

        return $out;
    }

    /** What's wrong with a freelance day, in words (none: it can be saved). */
    private function valueProblems(Timesheet $sheet, Carbon $date, array $values, ?WorkSite $site, Collection $columns): array
    {
        $problems = [];
        $on = $date->format('j M');
        $period = PayPeriod::ofSheet($sheet);
        $hours = 0.0;

        foreach ($values as $key => $value) {
            $column = $columns[$key];
            if ($column['type'] === 'hours') {
                if (!is_numeric($value) || $value < 0 || $value > 24) {
                    $problems[] = "{$on}: {$column['label']} must be 0 to 24 hours.";
                    continue;
                }
                $hours += (float) $value;
            }
            if ($column['feeds'] === 'overtime' && $column['day_kind'] && $column['day_kind'] !== $period->dayKind($date)) {
                $problems[] = "{$on} is a " . $period->dayKind($date) . ", not for {$column['label']}.";
            }
            if ($column['requires_offshore'] && !$site?->is_offshore) {
                $problems[] = "{$on}: {$column['label']} needs an offshore site.";
            }
        }
        if ($hours > 24) {
            $problems[] = "{$on}: more than 24 hours.";
        }

        return $problems;
    }

    /**
     * What stops a staff sheet being sent: per day, overtime that needed a request approved first and has none,
     * and planned overtime not yet confirmed.
     */
    private function submitProblems(Timesheet $sheet): array
    {
        $period = PayPeriod::ofSheet($sheet);
        $approvedOn = $this->approvedOvertimeDates($sheet);
        $problems = [];
        foreach ($sheet->days()->with('entries')->get() as $day) {
            $on = $day->date->format('j M');
            $kind = $period->dayKind($day->date);
            $split = $this->split($kind, $day->entries->map(fn ($e) => $this->entryData($e))->all());
            if ($split['overtime'] - $split['linked_overtime'] > 0 && $this->overtime->needsAdvance($kind) && !$approvedOn->has($day->date->toDateString())) {
                $problems[] = "{$on}: there's no approved overtime request for this day.";
            }
            if ($day->entries->contains('planned', true)) {
                $problems[] = "{$on}: confirm or remove the planned overtime.";
            }
        }

        return $problems;
    }

    /** Per column: hours added up or days ticked; days worked and leave days; on staff sheets, hours per site. */
    public function totals(Timesheet $sheet): array
    {
        $days = $sheet->relationLoaded('days') ? $sheet->days : $sheet->days()->get();
        $totals = ['columns' => []];
        foreach ($this->columns($sheet->template) as $c) {
            $totals['columns'][$c['key']] = $c['type'] === 'check'
                ? $days->filter(fn ($d) => !empty($d->values[$c['key']]))->count()
                : round((float) $days->sum(fn ($d) => (float) ($d->values[$c['key']] ?? 0)), 2);
        }
        // A day worked: one with anything in the "worked" columns (setting), not on leave.
        $worked = $sheet->isFreelance()
            ? (array) setting('payroll.freelance_worked_columns', ['working_day'])
            : (array) setting('payroll.timesheet_worked_columns', ['normal_hours']);
        $totals['days_worked'] = $days->filter(fn ($d) => !$d->is_leave && collect($worked)->contains(fn ($k) => !empty($d->values[$k])))->count();
        $totals['leave_days'] = $days->where('is_leave', true)->count();

        // Hours per site (e.g. for billing clients).
        if (!$sheet->isFreelance()) {
            $period = PayPeriod::ofSheet($sheet);
            $sites = [];
            $days->loadMissing('entries.workSite');
            foreach ($days as $day) {
                $entries = $day->entries->values();
                $split = $this->split($period->dayKind($day->date), $entries->map(fn ($e) => $this->entryData($e))->all());
                foreach ($split['entries'] as $i => $hours) {
                    $site = $entries[$i]->workSite;
                    $key = $site?->code ?? '';
                    $sites[$key] ??= ['code' => $site?->code, 'name' => $site?->name, 'normal_hours' => 0.0, 'overtime_hours' => 0.0, 'days' => []];
                    $sites[$key]['normal_hours'] = round($sites[$key]['normal_hours'] + $hours['normal'], 2);
                    $sites[$key]['overtime_hours'] = round($sites[$key]['overtime_hours'] + $hours['overtime'], 2);
                    $sites[$key]['days'][$day->date->toDateString()] = true;
                }
            }
            $totals['sites'] = collect($sites)->map(fn ($s) => array_merge($s, ['days' => count($s['days'])]))->sortBy('code')->values()->all();
        }

        return $totals;
    }

    /** Send for approval: the timesheet workflow (e.g. supervisor, then Operations Manager / CEO). */
    public function submit(Timesheet $sheet, User $user): Timesheet
    {
        if (!$sheet->isEditable()) {
            throw new UserFacingException('This timesheet has already been sent for approval.');
        }
        $sheet->load('days');
        if ($sheet->isFreelance()) {
            $columns = collect($this->columns($sheet->template))->keyBy('key');
            $problems = array_merge(...$sheet->days->map(fn ($d) => $this->valueProblems($sheet, $d->date, $d->values ?? [], $d->workSite, $columns))->all() ?: [[]]);
        } else {
            $problems = $this->submitProblems($sheet);
        }
        if ($problems) {
            throw new UserFacingException(implode(' ', array_unique($problems)));
        }
        if ($sheet->days->every(fn ($d) => empty($d->values))) {
            throw new UserFacingException('Fill in the days before sending the timesheet.');
        }

        return DB::transaction(function () use ($sheet, $user) {
            $sheet->update(['status' => Timesheet::SUBMITTED, 'submitted_at' => now(), 'totals' => $this->totals($sheet)]);
            $this->approvals->start($sheet, ApprovalProcess::TIMESHEET, $sheet->employee, $user);

            return $sheet->fresh();
        });
    }

    /** The workflow finished: approved (and, with pay from timesheets, applied to pay), or sent back with the reason. */
    public function finished(Timesheet $sheet, Approval $approval): void
    {
        if ($approval->status === Approval::APPROVED) {
            $sheet->update(['status' => Timesheet::APPROVED, 'approved_at' => now()]);
            if (setting('payroll.time_source', 'manual') === 'timesheet') {
                $this->apply($sheet);
            }
        } elseif ($approval->status === Approval::REJECTED) {
            $sheet->update([
                'status'           => Timesheet::REJECTED,
                'rejected_at'      => now(),
                'rejection_reason' => $approval->decisions()->where('decision', 'rejected')->latest('id')->value('comment'),
            ]);
        }
        $sheet->employee?->userAccount?->notify(new \App\Notifications\ApprovalDecidedNotification($approval, '/self-service/timesheet'));
    }

    /**
     * The employee takes their sent timesheet back to correct it (an honest mistake), while it's still waiting:
     * the approval is withdrawn (it leaves the approvers' inbox, and they're told) and the sheet is a draft again.
     * Steps already approved are approved again on the new submission. Once approved, only HR can reopen it.
     */
    public function withdraw(Timesheet $sheet): Timesheet
    {
        if ($sheet->status !== Timesheet::SUBMITTED || !$sheet->approval?->isPending()) {
            throw new UserFacingException('Only a timesheet still waiting for approval can be taken back.');
        }

        $approval = $sheet->approval;
        // Whose turn it was, and anyone who already approved a step (they'll be asked again).
        $told = collect($approval->current_approver_ids ?? [])->merge($approval->decisions()->pluck('decided_by'))->unique();

        $sheet = DB::transaction(function () use ($sheet, $approval) {
            $this->approvals->cancel($approval);
            $sheet->update(['status' => Timesheet::DRAFT, 'submitted_at' => null]);

            return $sheet->fresh();
        });
        User::whereIn('id', $told)->get()->each->notify(new \App\Notifications\ApprovalWithdrawnNotification($approval->fresh()));

        return $sheet;
    }

    /**
     * Take a sent or approved timesheet back to draft for corrections (HR or the timekeeper). What an approved
     * one fed into pay is withdrawn, unless a pay run sent for approval already holds it.
     */
    public function reopen(Timesheet $sheet): Timesheet
    {
        if ($sheet->isEditable()) {
            throw new UserFacingException('This timesheet is already open.');
        }

        return DB::transaction(function () use ($sheet) {
            if ($sheet->approval?->isPending()) {
                $this->approvals->cancel($sheet->approval);
            }
            if ($sheet->status === Timesheet::APPROVED) {
                $this->unapply($sheet);
            }
            $sheet->update(['status' => Timesheet::DRAFT, 'approved_at' => null, 'submitted_at' => null]);

            return $sheet->fresh();
        });
    }

    /**
     * Feed an approved timesheet into pay (again from scratch): its per-unit columns and days worked become
     * approved time inputs for its pay month; its overtime hours confirm the hours worked on overtime approved
     * in advance, or (when overtime is claimed after the work) become approved overtime.
     */
    public function apply(Timesheet $sheet): void
    {
        DB::transaction(function () use ($sheet) {
            $this->unapply($sheet);
            $sheet->load('days', 'employee');
            $totals = $this->totals($sheet);
            $sheet->update(['totals' => $totals]);

            foreach ($this->columns($sheet->template) as $c) {
                if ($c['feeds'] === 'time_input' && $c['component'] && ($component = PayComponent::where('code', $c['component'])->where('active', true)->first())) {
                    $this->timeInputs->fromTimesheet($sheet, $component, (float) $totals['columns'][$c['key']]);
                }
            }
            if (PayComponent::daysWorkedInUse() && ($daysWorked = PayComponent::where('code', PayComponent::DAYS_WORKED)->first())) {
                $this->timeInputs->fromTimesheet($sheet, $daysWorked, (float) $totals['days_worked']);
            }

            if (!$sheet->isFreelance()) {
                $this->applyOvertime($sheet);
            }
        });
    }

    /**
     * A staff sheet's overtime: entries for overtime approved in advance confirm its hours worked (none left
     * means none worked); overtime approved in advance with no entry takes the day's other overtime (as before
     * entries existed). What's left is claimed by the sheet (an approved request) on days that don't need one
     * approved first.
     */
    private function applyOvertime(Timesheet $sheet): void
    {
        $period = PayPeriod::ofSheet($sheet);
        $requests = OvertimeRequest::where('employee_id', $sheet->employee_id)->where('status', 'approved')->where('requested_in_advance', true)
            ->whereNull('timesheet_id')->whereDate('work_date', '>=', $sheet->period_start)->whereDate('work_date', '<=', $sheet->period_end)
            ->get()->groupBy(fn ($r) => $r->work_date->toDateString());
        $planned = TimesheetEntry::withTrashed()->whereIn('overtime_request_id', $requests->flatten()->pluck('id'))->pluck('overtime_request_id')->flip();

        foreach ($sheet->days()->with('entries.workSite')->get() as $day) {
            $kind = $period->dayKind($day->date);
            $entries = $day->entries->values();
            $split = $this->split($kind, $entries->map(fn ($e) => $this->entryData($e))->all());
            $unlinked = round($split['overtime'] - $split['linked_overtime'], 2);

            foreach ($requests->get($day->date->toDateString(), collect()) as $request) {
                $linked = $entries->filter(fn ($e) => $e->overtime_request_id === $request->id && !$e->planned);
                if ($linked->isNotEmpty() || $planned->has($request->id)) {
                    $request->update(['actual_hours' => round((float) $linked->sum('hours'), 2), 'timesheet_id' => $sheet->id]);
                } elseif ($unlinked > 0) {
                    $request->update(['actual_hours' => $unlinked, 'timesheet_id' => $sheet->id]);
                    $unlinked = 0.0;
                }
            }

            if ($unlinked > 0 && !$this->overtime->needsAdvance($kind) && ($type = $this->overtime->typeFor($day->date))) {
                // Claimed on the sheet: the approved timesheet is the claim. Overtime worked at an offshore site is its
                // own claim with the any-day type (e.g. offshore) paid on top; the rest is the day's type alone. Each
                // claim's site: the last entry with that overtime.
                $worked = collect($split['entries'])->filter(fn ($h) => $h['overtime'] > 0)
                    ->map(fn ($h, $i) => ['entry' => $entries[$i], 'hours' => $h['overtime']])
                    ->reject(fn ($w) => $w['entry']->overtime_request_id);
                [$offshore, $onshore] = $worked->partition(fn ($w) => (bool) $w['entry']->workSite?->is_offshore);
                $addon = $offshore->isNotEmpty() ? $this->overtime->onTopForOffshore() : null;
                $offshoreHours = $addon ? round(min($unlinked, (float) $offshore->sum('hours')), 2) : 0.0;
                $claims = [[$offshoreHours, $addon, $offshore->last()['entry'] ?? null], [round($unlinked - $offshoreHours, 2), null, ($onshore->last() ?? $offshore->last())['entry'] ?? null]];

                foreach ($claims as [$hours, $onTop, $entry]) {
                    if ($hours <= 0) {
                        continue;
                    }
                    $at = $entry?->workSite;
                    OvertimeRequest::create([
                        'employee_id' => $sheet->employee_id, 'overtime_type_id' => $type->id, 'addon_type_id' => $onTop?->id,
                        'work_date' => $day->date->toDateString(), 'hours_requested' => $hours, 'approved_hours' => $hours,
                        'location' => $at?->name, 'work_site_id' => $at?->id,
                        'reason' => "Timesheet {$sheet->label()}", 'status' => 'approved', 'timesheet_id' => $sheet->id,
                    ]);
                }
            }
        }
    }

    /** Withdraw what an approved timesheet fed into pay; refused once a pay run sent for approval holds any of it. */
    public function unapply(Timesheet $sheet): void
    {
        $inputs = TimeInput::where('timesheet_id', $sheet->id)->whereIn('status', ['pending', 'approved'])->with('payRun')->get();
        $created = OvertimeRequest::where('timesheet_id', $sheet->id)->where('requested_in_advance', false)->with('payRun')->get();
        $confirmed = OvertimeRequest::where('timesheet_id', $sheet->id)->where('requested_in_advance', true)->with('payRun')->get();
        $locked = $inputs->concat($created)->concat($confirmed)->first(fn ($r) => $r->payRun && !$r->payRun->status->isOpen());
        if ($locked) {
            throw new UserFacingException("This timesheet is already in {$locked->payRun->name}, which has been sent for approval, so it can't change.");
        }

        $runs = $inputs->concat($created)->concat($confirmed)->pluck('payRun')->filter()->unique('id');
        foreach ($runs as $run) {
            if ($run->status === \App\Enums\Payroll\PayRunStatus::CALCULATED) {
                $run->update(['status' => \App\Enums\Payroll\PayRunStatus::DRAFT]);
            }
        }
        foreach ($inputs as $input) {
            $input->update(['status' => Approval::CANCELLED, 'pay_run_id' => null]);
            $input->delete();
        }
        foreach ($created as $request) {
            $request->update(['status' => Approval::CANCELLED, 'pay_run_id' => null]);
            $request->delete();
        }
        foreach ($confirmed as $request) {
            $request->update(['actual_hours' => null, 'timesheet_id' => null, 'pay_run_id' => null]);
        }
    }

    /**
     * Read a filled FM-06 (Excel) into the sheet. Rows are matched to days by position (the template's first day
     * row is the period's first day); each column by its letter (`sheet_column`); the site by its code. Ticks
     * count as yes (in an hours column: a full day's hours). All or nothing.
     */
    public function importFm06(Timesheet $sheet, string $path, int $firstRow = 10, string $siteColumn = 'L'): Timesheet
    {
        $cells = IOFactory::load($path)->getSheet(0);
        $columns = collect($this->columns($sheet->template))->filter(fn ($c) => $c['sheet_column']);
        if ($columns->isEmpty()) {
            throw new UserFacingException('No timesheet column has a column letter for imports. Set them in Payroll → Settings → Timesheets.');
        }
        $sites = WorkSite::where('active', true)->get()->keyBy(fn ($s) => strtoupper($s->code));
        $hoursPerDay = (float) setting('payroll.hours_per_day', 8);
        $problems = [];
        $days = [];

        foreach ($sheet->days()->get()->values() as $i => $day) {
            $row = $firstRow + $i;
            $values = [];
            foreach ($columns as $c) {
                $raw = trim((string) $cells->getCell($c['sheet_column'] . $row)->getFormattedValue());
                if ($raw === '' || in_array($raw, ['-', '–', '—'], true)) {
                    continue;
                }
                $tick = in_array(mb_strtolower($raw), self::TICKS, true);
                $values[$c['key']] = match (true) {
                    $c['type'] === 'check' => $tick ? true : (is_numeric($raw) && (float) $raw > 0),
                    is_numeric($raw)       => (float) $raw,
                    $tick                  => $hoursPerDay,
                    default                => $raw,
                };
            }
            $code = strtoupper(trim((string) $cells->getCell($siteColumn . $row)->getFormattedValue()));
            $site = null;
            if ($code !== '' && !in_array($code, ['-', '–', '—'], true)) {
                $site = $sites->get($code);
                if (!$site) {
                    $problems[] = $day->date->format('j M') . ": no site with the code {$code}.";
                }
            }
            $days[] = ['date' => $day->date->toDateString(), 'values' => $values, 'work_site_uuid' => $site?->uuid];
        }
        if ($problems) {
            throw new UserFacingException(implode(' ', $problems));
        }

        return $this->save($sheet, $days);
    }

    /** Employees paid by a regular run with no approved timesheet for its month (when pay comes from timesheets). */
    public function missingFor(PayRun $run, Collection $employeeIds): array
    {
        if ($run->type !== 'regular' || setting('payroll.time_source', 'manual') !== 'timesheet' || !feature('payroll.timesheets')) {
            return [];
        }
        $approved = Timesheet::where(['year' => $run->year, 'month' => $run->month, 'status' => Timesheet::APPROVED])
            ->whereIn('employee_id', $employeeIds)->pluck('employee_id')->flip();

        return $employeeIds->reject(fn ($id) => $approved->has($id))->values()->all();
    }
}
