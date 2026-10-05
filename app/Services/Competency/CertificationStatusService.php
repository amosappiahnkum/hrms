<?php

namespace App\Services\Competency;

use App\Models\Competency\PositionCertification;
use App\Models\EmployeeCertification;
use App\Models\SelfService\Employee;
use Illuminate\Support\Collection;

/**
 * Where employees stand against the certificates their positions require: each requirement is
 * valid, expiring (within EXPIRING_DAYS), expired or missing. A mandatory one that is missing or
 * expired is a gap, and blocks authorization.
 */
class CertificationStatusService
{
    public const EXPIRING_DAYS = 90;

    /**
     * @param Collection<Employee> $employees (with jobDetail)
     * @return Collection<int, Collection> employee id => one row per required certificate
     */
    public function forEmployees(Collection $employees): Collection
    {
        $employees = (new \Illuminate\Database\Eloquent\Collection($employees->all()))->loadMissing('jobDetail');
        $requirements = PositionCertification::whereIn('position_id', $employees->pluck('jobDetail.position_id')->filter()->unique())
            ->whereHas('type')->with('type')->get()->groupBy('position_id');
        $certificates = $this->certificatesOf($employees->pluck('id'));

        return $employees->mapWithKeys(fn (Employee $e) => [$e->id => ($requirements->get($e->jobDetail?->position_id) ?? collect())
            ->sortBy(fn ($r) => [$r->mandatory ? 0 : 1, $r->type->name])
            ->map(fn (PositionCertification $r) => $this->row($r, $certificates->get($e->id) ?? collect()))
            ->values()]);
    }

    public function forEmployee(Employee $employee): Collection
    {
        return $this->forEmployees(collect([$employee]))->get($employee->id) ?? collect();
    }

    /** Mandatory certificates the employee lacks or has let expire. */
    public function gaps(Collection $rows): Collection
    {
        return $rows->filter(fn ($r) => $r['mandatory'] && in_array($r['status'], ['missing', 'expired'], true))->values();
    }

    /** The employee holds a certificate of this type that is valid today. */
    public function holdsValid(int $employeeId, int $typeId): bool
    {
        return EmployeeCertification::where('employee_id', $employeeId)->where('certification_type_id', $typeId)->get()
            ->contains(fn (EmployeeCertification $c) => $c->isValidOn(now()));
    }

    /** Each employee's typed certificates, best first (no expiry, then latest expiry). */
    private function certificatesOf(Collection $employeeIds): Collection
    {
        return EmployeeCertification::whereIn('employee_id', $employeeIds)->whereNotNull('certification_type_id')->get()
            ->sortByDesc(fn (EmployeeCertification $c) => $c->does_not_expire || !$c->expiry_date ? '9999-12-31' : $c->expiry_date->toDateString())
            ->groupBy('employee_id');
    }

    private function row(PositionCertification $requirement, Collection $certificates): array
    {
        $best = $certificates->firstWhere('certification_type_id', $requirement->certification_type_id);

        $status = match (true) {
            !$best                                  => 'missing',
            !$best->isValidOn(now())                => 'expired',
            !$best->does_not_expire && $best->expiry_date?->lte(now()->addDays(self::EXPIRING_DAYS)) => 'expiring',
            default                                 => 'valid',
        };

        return [
            'type'        => ['uuid' => $requirement->type->uuid, 'name' => $requirement->type->name],
            'mandatory'   => $requirement->mandatory,
            'status'      => $status,
            'certificate' => $best ? [
                'id'          => $best->id,
                'title'       => $best->title,
                'expiry_date' => $best->does_not_expire ? null : $best->expiry_date?->toDateString(),
            ] : null,
        ];
    }
}
