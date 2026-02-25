<?php

namespace App\Exports;

use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;

class EmployeeExport implements FromCollection, WithMapping, WithHeadings, ShouldAutoSize
{
    /**
     * @var AnonymousResourceCollection
     */
    private AnonymousResourceCollection $data;

    /**
     * @param $employeesResource
     */
    public function __construct($employeesResource){
        $this->data = $employeesResource;
    }

    /**
     * @return AnonymousResourceCollection
     */
    public function collection(): AnonymousResourceCollection
    {
        return $this->data;
    }

    /**
     * @return string[]
     */
    public function headings(): array
    {
        return [
            'Staff ID',
            'Name',
            'Department',
            'Rank',
            'Gtec Placement',
            'Gender',
            'D.O.B',
            'Phone Number'
        ];
    }

    /**
     * @param $row
     * @return array
     */
    public function map($row): array
    {
        return [
            $row->staff_id,
            $row->name,
            $row->department?->name ?? '-',
            $row->rank?->name ?? '-',
            $row->gtecPlacement?->name ?? '-',
            $row->gender,
            $row->dob,
            $row->contactDetail->telephone,
        ];
    }
}
