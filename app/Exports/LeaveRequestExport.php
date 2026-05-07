<?php

namespace App\Exports;

use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;

class LeaveRequestExport implements FromCollection, WithMapping, WithHeadings, ShouldAutoSize
{
    /**
     * @var Collection
     */
    private Collection $data;

    /**
     * @param $leaveRequests
     */
    public function __construct($leaveRequests){
        $this->data = $leaveRequests;
    }

    public function collection(): Collection
    {
        return $this->data;
    }

    public function headings(): array
    {
        return [
            'Staff ID',
            'Name',
            'Department',
            'Leave Type',
            'Start Date',
            'End Date',
            'Requested',
            'Approved',
            'Status'
        ];
    }

    public function map($row): array
    {
        return [
            $row->employee->staff_id,
            $row->employee->name,
            $row->employee->department->name,
            $row->leaveType->name,
            $row->start_date,
            $row->end_date,
            $row->days_requested,
            $row->days_approved,
            Str::upper(Str::replace('_', ' ', $row->status->value)),
        ];
    }
}
