<?php

namespace App\Services\Payroll\Sheets;

use App\Models\SelfService\Employee;
use Illuminate\Support\Collection;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Protection;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * Import templates shaped as a grid: one row per employee, one column per pay component. What
 * identifies a row (the employee's uuid, column A) and a column (the component's uuid, row 1) is
 * hidden and locked, so typing or editing staff IDs and codes can't point a value at the wrong
 * place. Staff ID, name and department show whose row it is and are locked too. Cells that mustn't
 * change (e.g. already paid) can be locked one by one. Filtering and sorting stay allowed.
 *
 * Layout: row 1 hidden (the sheet's own reference in A1, component uuids above their columns),
 * row 2 headings, data from row 3.
 */
class EmployeeGrid
{
    public const FIRST_ROW = 3;

    private const REFERENCE_HEADINGS = ['Ref', 'Staff ID', 'Name', 'Department'];

    /** The password on protected template sheets: stable for this installation, not published. */
    public static function password(): string
    {
        return substr(hash('sha256', 'payroll-templates|' . config('app.key')), 0, 16);
    }

    /**
     * Protect a sheet: everything locked except `$unlocked` (a range), with filtering, sorting and
     * column widths still allowed (a protection flag set to true would block them).
     */
    public static function protect(Worksheet $sheet, string $unlocked): void
    {
        $sheet->getProtection()->setSheet(true)->setAutoFilter(false)->setSort(false)->setFormatColumns(false)->setPassword(self::password());
        $sheet->getStyle($unlocked)->getProtection()->setLocked(Protection::PROTECTION_UNPROTECTED);
    }

    /** A second sheet explaining how to fill the first. */
    public static function guide(Spreadsheet $book, array $lines): void
    {
        $help = $book->createSheet()->setTitle('How to fill');
        $help->fromArray(array_map(fn ($l) => [$l], $lines));
        $help->getStyle('A1')->getFont()->setBold(true);
        $help->getColumnDimension('A')->setWidth(120);
        $book->setActiveSheetIndex(0);
    }

    public static function save(Spreadsheet $book): string
    {
        $path = tempnam(sys_get_temp_dir(), 'grid') . '.xlsx';
        (new Xlsx($book))->save($path);

        return $path;
    }

    /**
     * Build the grid.
     *
     * @param string $reference what the sheet is for (e.g. "time-inputs:2026-06"), checked on import
     * @param Collection<Employee> $employees rows (department loaded)
     * @param array<array{ref: string, heading: string, values: array<string, mixed>, locked?: array<string, true>}> $columns
     *        values and locked cells keyed by employee uuid
     * @param array{heading: string, values: array<string, mixed>}|null $notes a trailing free-text column
     */
    public static function build(string $title, string $reference, Collection $employees, array $columns, ?array $notes, array $guide): string
    {
        $book = new Spreadsheet();
        $sheet = $book->getActiveSheet()->setTitle(mb_substr($title, 0, 31));
        $firstValue = count(self::REFERENCE_HEADINGS) + 1;
        $lastIndex = $firstValue + count($columns) - 1 + ($notes ? 1 : 0);
        $letter = fn (int $i) => Coordinate::stringFromColumnIndex($i);
        $last = $letter(max($lastIndex, count(self::REFERENCE_HEADINGS)));
        $end = max(self::FIRST_ROW, self::FIRST_ROW + $employees->count() - 1);

        // Row 1 (hidden): the sheet's reference, and each component's uuid above its column.
        $sheet->setCellValue('A1', $reference);
        foreach (array_values($columns) as $i => $c) {
            $sheet->setCellValue([$firstValue + $i, 1], $c['ref']);
        }
        if ($notes) {
            $sheet->setCellValue([$lastIndex, 1], 'notes');
        }
        $sheet->getRowDimension(1)->setVisible(false);

        // Row 2: headings.
        $headings = [...self::REFERENCE_HEADINGS, ...array_column($columns, 'heading'), ...($notes ? [$notes['heading']] : [])];
        $sheet->fromArray($headings, null, 'A2');
        $sheet->getStyle("A2:{$last}2")->getFont()->setBold(true);
        $sheet->getStyle("A2:{$last}2")->getAlignment()->setWrapText(true);

        // Rows: the employee (reference columns), then their values.
        $r = self::FIRST_ROW;
        foreach ($employees as $e) {
            $sheet->setCellValueExplicit("A{$r}", $e->uuid, \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
            $sheet->setCellValueExplicit("B{$r}", (string) $e->staff_id, \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
            $sheet->setCellValue("C{$r}", trim(preg_replace('/\s+/', ' ', (string) $e->name)));
            $sheet->setCellValue("D{$r}", $e->department?->name);
            foreach (array_values($columns) as $i => $c) {
                $value = $c['values'][$e->uuid] ?? null;
                if ($value !== null) {
                    $sheet->setCellValue([$firstValue + $i, $r], $value);
                }
            }
            if ($notes && filled($notes['values'][$e->uuid] ?? null)) {
                $sheet->setCellValue([$lastIndex, $r], $notes['values'][$e->uuid]);
            }
            $r++;
        }

        self::protect($sheet, $lastIndex >= $firstValue ? "{$letter($firstValue)}" . self::FIRST_ROW . ":{$last}{$end}" : 'Z1000:Z1000');
        $sheet->getStyle('A1:' . $last . '2')->getProtection()->setLocked(Protection::PROTECTION_PROTECTED);
        $sheet->getStyle('A' . self::FIRST_ROW . ":D{$end}")->getProtection()->setLocked(Protection::PROTECTION_PROTECTED);
        $sheet->getStyle('A' . self::FIRST_ROW . ":D{$end}")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('EEF2F4');

        // Cells that mustn't change (e.g. already paid): locked and shaded.
        $r = self::FIRST_ROW;
        foreach ($employees as $e) {
            foreach (array_values($columns) as $i => $c) {
                if (isset($c['locked'][$e->uuid])) {
                    $cell = $letter($firstValue + $i) . $r;
                    $sheet->getStyle($cell)->getProtection()->setLocked(Protection::PROTECTION_PROTECTED);
                    $sheet->getStyle($cell)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('E5E7EB');
                }
            }
            $r++;
        }

        $sheet->setAutoFilter("A2:{$last}{$end}");
        $sheet->freezePane('E' . self::FIRST_ROW);
        foreach (range(1, max($lastIndex, 4)) as $i) {
            $sheet->getColumnDimension($letter($i))->setAutoSize($i <= 4);
            if ($i > 4) {
                $sheet->getColumnDimension($letter($i))->setWidth(18);
            }
        }
        $sheet->getColumnDimension('A')->setVisible(false);
        $sheet->getStyle('B' . self::FIRST_ROW . ":B{$end}")->getNumberFormat()->setFormatCode('@');

        self::guide($book, $guide);

        return self::save($book);
    }

    /**
     * Read a grid back.
     *
     * @return array{reference: ?string, rows: array<int, array{line: int, ref: ?string, label: string, values: array<string, mixed>, notes: mixed}>}
     *         values keyed by column ref (component uuid); rows keyed by spreadsheet line
     */
    public static function read(string $path): array
    {
        $sheet = IOFactory::load($path)->getSheet(0);
        $data = $sheet->toArray(null, true, false, false);
        $refs = $data[0] ?? [];
        $out = [];
        foreach (array_slice($data, self::FIRST_ROW - 1, null, true) as $i => $row) {
            $values = [];
            $notes = null;
            foreach ($refs as $c => $ref) {
                if ($c < count(self::REFERENCE_HEADINGS) || $ref === null || $ref === '') {
                    continue;
                }
                $cell = is_string($row[$c] ?? null) ? trim($row[$c]) : ($row[$c] ?? null);
                if ($ref === 'notes') {
                    $notes = $cell === '' ? null : $cell;
                } else {
                    $values[(string) $ref] = $cell === '' ? null : $cell;
                }
            }
            $staff = trim((string) ($row[1] ?? ''));
            $name = trim((string) ($row[2] ?? ''));
            $ref = trim((string) ($row[0] ?? ''));
            if ($ref === '' && $staff === '' && $name === '' && !array_filter($values, fn ($v) => $v !== null)) {
                continue;
            }
            $out[$i + 1] = ['line' => $i + 1, 'ref' => $ref ?: null, 'label' => $staff ?: $name ?: 'Row ' . ($i + 1), 'values' => $values, 'notes' => $notes];
        }

        return ['reference' => isset($data[0][0]) ? (string) $data[0][0] : null, 'rows' => $out];
    }
}
