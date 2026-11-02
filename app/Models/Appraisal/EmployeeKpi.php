<?php

namespace App\Models\Appraisal;

use App\Models\ApplicationModel;
use App\Models\SelfService\Employee;
use App\Models\User;
use App\Traits\HasUuid;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A KPI target agreed with an employee for a period, ahead of the appraisal. The employee sees their plan all year; the
 * appraisal for the period starts from it (AssessmentAttempt::startKpisFromPlan), and the supervisor records actuals there.
 */
class EmployeeKpi extends ApplicationModel
{
    use HasUuid, SoftDeletes;

    protected $fillable = [
        'employee_id',
        'period_start',
        'period_end',
        'description',
        'target',
        'order',
        'source_attempt_id',
        'set_by',
    ];

    protected $casts = [
        'period_start' => 'date',
        'period_end'   => 'date',
        'target'       => 'decimal:2',
    ];

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function setter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'set_by');
    }

    /** Plans for a period that overlaps [from, to]. */
    public function scopeOverlapping(Builder $query, CarbonInterface $from, CarbonInterface $to): Builder
    {
        return $query->whereDate('period_start', '<=', $to->toDateString())
            ->whereDate('period_end', '>=', $from->toDateString());
    }

    /**
     * Replace an employee's plan for exactly this period. Rows set in an appraisal keep pointing at it.
     *
     * @param array<int, array{description: string, target: numeric}> $kpis
     */
    public static function replacePlan(Employee $employee, CarbonInterface $from, CarbonInterface $to, array $kpis, ?int $by, ?int $sourceAttemptId = null): void
    {
        $period = self::where('employee_id', $employee->id)
            ->whereDate('period_start', $from->toDateString())
            ->whereDate('period_end', $to->toDateString());
        $sourceAttemptId ??= (clone $period)->whereNotNull('source_attempt_id')->value('source_attempt_id');
        $period->delete();

        foreach (array_values($kpis) as $index => $kpi) {
            self::create([
                'employee_id'       => $employee->id,
                'period_start'      => $from->toDateString(),
                'period_end'        => $to->toDateString(),
                'description'       => $kpi['description'],
                'target'            => $kpi['target'],
                'order'             => $index,
                'source_attempt_id' => $sourceAttemptId,
                'set_by'            => $by,
            ]);
        }

        if ($employee->department_id) {
            foreach ($kpis as $kpi) {
                JobKpi::upsertForDepartment($employee->department_id, $kpi['description'], $by);
            }
        }
    }
}
