<?php

namespace App\Exports;

use App\Models\Appraisal\AssessmentAttempt;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithStyles;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class AppraisalExport implements FromCollection, WithMapping, WithHeadings, ShouldAutoSize, WithStyles
{
    private Collection $data;

    public function __construct(Collection $data)
    {
        $this->data = $data;
    }

    public function collection(): Collection
    {
        return $this->data;
    }

    public function headings(): array
    {
        return [
            'Employee Name',
            'Email',
            'Department',
            'Assessment Window',
            'Assessment',
            'Confirmed By (Supervisor)',
            'Submitted Date',
            'Confirmed Date',
            'Finalized Date',
            'Score',
            'Self Score',
            'KPI Score (%)',
            'Status',
        ];
    }

    public function map($row): array
    {
        /** @var AssessmentAttempt $row */
        $statusMap = [
            'supervisor_confirmed' => 'Pending HR Review',
            'completed'            => 'Completed',
        ];

        return [
            $row->user?->name ?? '—',
            $row->user?->email ?? '—',
            $row->user?->employee?->department?->name ?? '—',
            $row->window?->title ?? '—',
            $row->window?->assessment?->name ?? '—',
            $row->supervisor?->name ?? '—',
            $row->submitted_at?->format('d M Y') ?? '—',
            $row->supervisor_confirmed_at?->format('d M Y') ?? '—',
            $row->finalized_at?->format('d M Y') ?? '—',
            $row->score !== null ? number_format((float) $row->score, 2) : '—',
            $row->self_score !== null ? number_format((float) $row->self_score, 2) : '—',
            $row->kpi_score !== null ? number_format((float) $row->kpi_score, 2) . '%' : '—',
            $statusMap[$row->status] ?? $row->status,
        ];
    }

    public function styles(Worksheet $sheet): array
    {
        return [
            1 => ['font' => ['bold' => true]],
        ];
    }
}
