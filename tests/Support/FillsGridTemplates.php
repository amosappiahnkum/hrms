<?php

namespace Tests\Support;

use Illuminate\Http\UploadedFile;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/** Fill a downloaded grid template (EmployeeGrid) the way a person would, and upload it. */
trait FillsGridTemplates
{
    /**
     * @param callable(string $staffId): ?array $fill heading (or the start of one) => value, for that employee's row
     */
    protected function fillGrid(string $path, callable $fill): UploadedFile
    {
        $book = IOFactory::load($path);
        $sheet = $book->getSheet(0);
        $headings = $sheet->rangeToArray('A2:' . $sheet->getHighestColumn() . '2')[0];
        for ($r = 3; $r <= $sheet->getHighestRow(); $r++) {
            foreach ($fill((string) $sheet->getCell("B{$r}")->getValue()) ?? [] as $heading => $value) {
                $c = collect($headings)->search(fn ($h) => str_starts_with((string) $h, $heading));
                $this->assertNotFalse($c, "No column {$heading}");
                $sheet->setCellValue([$c + 1, $r], $value);
            }
        }
        $out = tempnam(sys_get_temp_dir(), 'grid') . '.xlsx';
        (new Xlsx($book))->save($out);

        return new UploadedFile($out, 'sheet.xlsx', null, null, true);
    }

    /** The file a download response sent. */
    protected function downloaded($response): string
    {
        return $response->assertOk()->baseResponse->getFile()->getPathname();
    }
}
