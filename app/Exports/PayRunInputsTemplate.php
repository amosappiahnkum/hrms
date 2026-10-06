<?php

namespace App\Exports;

use App\Models\Payroll\PayComponent;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithTitle;

/** The pay run inputs template: one row per item, with the components that can be used listed below. */
class PayRunInputsTemplate implements FromArray, WithHeadings, WithTitle
{
    public function headings(): array
    {
        return ['Staff ID', 'Component code', 'Quantity', 'Amount', 'Notes'];
    }

    public function array(): array
    {
        $rows = [['', '', '', '', ''], ['', '', '', '', ''], ['Components you can use (delete these rows before importing):', '', '', '', '']];
        foreach (PayComponent::where('active', true)->where('is_system', false)->orderBy('code')->get() as $c) {
            $needs = in_array($c->calculation->value, ['hourly_multiplier', 'rate_per_unit'], true) ? 'Quantity (' . ($c->unit ?: 'hours') . ')' : ($c->calculation->value === 'manual' ? 'Amount' : 'Optional amount');
            $rows[] = ['', $c->code, '', '', "{$c->name}: {$needs}"];
        }

        return $rows;
    }

    public function title(): string
    {
        return 'Inputs';
    }
}
