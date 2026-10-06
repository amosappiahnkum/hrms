<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithTitle;

/** A sheet from rows already worked out: headings (optional), rows, title. */
class ArrayExport implements FromArray, WithHeadings, WithTitle, ShouldAutoSize
{
    public function __construct(private readonly array $headings, private readonly array $rows, private readonly string $title = 'Sheet1')
    {
    }

    public function headings(): array
    {
        return $this->headings;
    }

    public function array(): array
    {
        return $this->rows;
    }

    public function title(): string
    {
        return mb_substr($this->title, 0, 31);
    }
}
