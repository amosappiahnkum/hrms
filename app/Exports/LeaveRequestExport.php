<?php

namespace App\Exports;

use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;

class LeaveRequestExport implements FromCollection, WithMapping, WithHeadings, ShouldAutoSize
{
    /**
     * @var AnonymousResourceCollection
     */
    private Collection $data;

    /**
     * @param $leaveRequestResources
     */
    public function __construct($leaveRequestResources){
        $this->data = $leaveRequestResources;
    }

    public function collection()
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
        Log::info('row', [$row->employee->staff_id]);
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
