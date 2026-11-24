<?php

namespace App\Services\Srms;

use App\Models\SelfService\Employee;
use Illuminate\Support\Facades\Http;

/**
 * TTU only (HRMS serves other organisations too): on where the `integrations.srms` feature is switched on and
 * SRMS_API_URL / SRMS_API_KEY are set. Off by default.
 *
 * Sends an employee's details to SRMS, which updates its staff record with the same staff number. Not everyone in
 * HRMS is in SRMS: SRMS answers 404 for them and nothing is created there. Only what SRMS uses is sent.
 */
class SrmsStaffSync
{
    public static function enabled(): bool
    {
        return feature('integrations.srms') && filled(config('srms.url')) && filled(config('srms.key'));
    }

    /** @return array<string, mixed> */
    public function payload(Employee $employee, bool $biodataUpdated): array
    {
        $employee->loadMissing(['contactDetail', 'rank', 'jobDetail.position']);

        return [
            'staff_id' => trim((string) $employee->staff_id),
            'title' => $employee->title,
            'first_name' => $employee->first_name,
            'middle_name' => $employee->middle_name,
            'last_name' => $employee->last_name,
            'work_email' => $employee->contactDetail?->work_email,
            // A mobile: SRMS texts it.
            'telephone' => $employee->contactDetail?->telephone,
            'rank' => $employee->rank?->name,
            'job_title' => $employee->jobDetail?->position?->name,
            // Archived or terminated: no longer working here.
            'active' => ! $employee->trashed() && $employee->termination_date === null,
            'biodata_updated' => $biodataUpdated,
        ];
    }

    /**
     * @return 'updated'|'not_in_srms'
     *
     * @throws \Illuminate\Http\Client\RequestException SRMS unreachable or refusing (the job retries)
     */
    public function push(Employee $employee, bool $biodataUpdated = false): string
    {
        $response = Http::withToken((string) config('srms.key'))->acceptJson()->timeout(15)
            ->post(rtrim((string) config('srms.url'), '/').'/staff/bio-data', $this->payload($employee, $biodataUpdated));

        if ($response->notFound()) {
            return 'not_in_srms';
        }
        $response->throw();

        return 'updated';
    }
}
