<?php

namespace App\Services\Payroll;

use App\Models\Config\Setting;
use App\Models\Payroll;
use App\Models\SelfService\Employee;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Arr;
use Spatie\Activitylog\Models\Activity;

/**
 * The payroll audit trail: every change to pay details, components, rates, runs, inputs, loans,
 * overtime, time inputs, approvals and payroll settings, with who made it and the old and new
 * values. Read from the activity log every model already writes to. Calculated payslips are left
 * out: they're the result of a run, which is itself in the trail.
 */
class PayrollAudit
{
    /** What each kind of record is called, by model. */
    public const SUBJECTS = [
        Payroll\EmployeePayProfile::class   => 'Pay details',
        Payroll\EmployeePayComponent::class => 'Employee pay component',
        Payroll\PayComponent::class         => 'Pay component',
        Payroll\StatutoryRateSet::class     => 'Statutory rates',
        Payroll\ExchangeRate::class         => 'Exchange rate',
        Payroll\PaymentFileLayout::class    => 'Payment file layout',
        Payroll\ApprovalWorkflow::class     => 'Approval workflow',
        Payroll\ApprovalDecision::class     => 'Approval decision',
        Payroll\PayRun::class               => 'Pay run',
        Payroll\PayRunInput::class          => 'Pay run input',
        Payroll\OvertimeType::class         => 'Overtime type',
        Payroll\OvertimeRequest::class      => 'Overtime request',
        Payroll\TimeInput::class            => 'Time input',
        Payroll\LoanType::class             => 'Loan type',
        Payroll\Loan::class                 => 'Loan',
        Payroll\LoanRepayment::class        => 'Loan repayment',
        Setting::class                      => 'Payroll setting',
    ];

    /** Attributes not worth showing (keys and bookkeeping). */
    private const HIDDEN = ['id', 'uuid', 'created_at', 'updated_at', 'deleted_at'];

    public function query(array $filters): Builder
    {
        $types = collect(self::SUBJECTS)->keys()
            ->when($filters['subject'] ?? null, fn ($c, $s) => $c->filter(fn ($class) => class_basename($class) === $s))
            ->mapWithKeys(fn ($class) => [(new $class)->getMorphClass() => $class]);
        $settings = Setting::where(fn ($q) => $q->where('key', 'like', 'payroll.%')->orWhere('key', 'like', 'features.payroll.%'))->pluck('id');

        return Activity::query()
            ->where(function ($q) use ($types, $settings) {
                $q->whereIn('subject_type', $types->keys()->reject(fn ($t) => $types[$t] === Setting::class));
                if ($types->contains(Setting::class)) {
                    $q->orWhere(fn ($w) => $w->where('subject_type', (new Setting)->getMorphClass())->whereIn('subject_id', $settings));
                }
            })
            ->when($filters['from'] ?? null, fn ($q, $v) => $q->whereDate('created_at', '>=', $v))
            ->when($filters['to'] ?? null, fn ($q, $v) => $q->whereDate('created_at', '<=', $v))
            ->when($filters['user'] ?? null, fn ($q, $v) => $q->whereHasMorph('causer', '*', fn ($c) => $c->where('uuid', $v)))
            ->with('causer')
            ->latest('id');
    }

    public function page(array $filters, int $perPage): LengthAwarePaginator
    {
        $page = $this->query($filters)->paginate($perPage);
        $page->setCollection($this->describe($page->getCollection()));

        return $page;
    }

    /** Rows for the spreadsheet. */
    public function rows(array $filters, int $limit = 20000): array
    {
        return $this->describe($this->query($filters)->limit($limit)->get())->map(fn ($r) => [
            $r['at'], $r['by'], $r['what'], $r['event'], $r['record'], $r['employee'],
            collect($r['changes'])->map(fn ($c) => "{$c['field']}: " . $this->text($c['from']) . ' → ' . $this->text($c['to']))->implode('; '),
        ])->all();
    }

    private function describe($activities)
    {
        $settings = Setting::whereIn('id', $activities->where('subject_type', (new Setting)->getMorphClass())->pluck('subject_id'))->pluck('key', 'id');
        $byMorph = collect(self::SUBJECTS)->mapWithKeys(fn ($label, $class) => [(new $class)->getMorphClass() => [$class, $label]]);
        // Whose record it is: from the change itself, else from the record (updates log only what changed).
        $owners = $activities->groupBy('subject_type')->flatMap(function ($group, $type) use ($byMorph) {
            $class = $byMorph[$type][0] ?? null;
            if (!$class || !in_array('employee_id', (new $class)->getFillable(), true)) {
                return [];
            }
            $query = in_array(\Illuminate\Database\Eloquent\SoftDeletes::class, class_uses_recursive($class), true) ? $class::withTrashed() : $class::query();

            return $query->whereIn('id', $group->pluck('subject_id'))->pluck('employee_id', 'id')
                ->mapWithKeys(fn ($employeeId, $id) => ["{$type}:{$id}" => $employeeId]);
        });
        $employeeOf = fn (Activity $a) => Arr::get($a->properties, 'attributes.employee_id') ?? Arr::get($a->properties, 'old.employee_id') ?? $owners["{$a->subject_type}:{$a->subject_id}"] ?? null;
        $employees = Employee::withTrashed()->whereIn('id', $activities->map($employeeOf)->filter()->unique())->get()->keyBy('id');

        return $activities->map(function (Activity $a) use ($settings, $byMorph, $employees, $employeeOf) {
            [$class, $label] = $byMorph[$a->subject_type] ?? [null, class_basename((string) $a->subject_type)];
            $new = (array) Arr::get($a->properties, 'attributes', []);
            $old = (array) Arr::get($a->properties, 'old', []);
            $employeeId = $employeeOf($a);

            return [
                'id'       => $a->id,
                'at'       => $a->created_at?->toIso8601String(),
                'by'       => $a->causer?->name ?? 'System',
                'what'     => $label,
                'event'    => $a->event ?? $a->description,
                'record'   => $class === Setting::class ? ($settings[$a->subject_id] ?? null) : ($new['name'] ?? $old['name'] ?? $new['code'] ?? null),
                'employee' => $employeeId ? trim(preg_replace('/\s+/', ' ', (string) $employees->get($employeeId)?->name)) : null,
                'changes'  => collect(array_unique(array_merge(array_keys($new), array_keys($old))))
                    ->reject(fn ($f) => in_array($f, self::HIDDEN, true) || str_ends_with($f, '_id'))
                    ->filter(fn ($f) => ($old[$f] ?? null) != ($new[$f] ?? null))
                    ->map(fn ($f) => ['field' => str_replace('_', ' ', $f), 'from' => $old[$f] ?? null, 'to' => $new[$f] ?? null])
                    ->values()->all(),
            ];
        });
    }

    private function text(mixed $v): string
    {
        return match (true) {
            $v === null => '—',
            is_bool($v) => $v ? 'yes' : 'no',
            is_array($v) => json_encode($v),
            default => (string) $v,
        };
    }
}
