<?php

namespace App\Imports;

use App\Enums\QuestionType;
use Maatwebsite\Excel\Concerns\Importable;
use Maatwebsite\Excel\Concerns\SkipsUnknownSheets;
use Maatwebsite\Excel\Concerns\WithMultipleSheets;

class QuestionImport implements WithMultipleSheets, SkipsUnknownSheets
{
    use Importable;

    public int $imported = 0;
    public array $errors = [];

    /**
     * Keyed by sheet tab name so missing tabs are skipped gracefully.
     * Names must match the titles set in QuestionTemplateExport.
     */
    public function sheets(): array
    {
        return [
            'Multiple Choice' => new QuestionSheetImport(QuestionType::MULTIPLE_CHOICE, 'appraisal', $this),
            'Rating'          => new QuestionSheetImport(QuestionType::RATING,           'appraisal', $this),
            'Yes or No'       => new QuestionSheetImport(QuestionType::YES_NO,           'appraisal', $this),
            'Open Text'       => new QuestionSheetImport(QuestionType::OPEN_TEXT,        'appraisal', $this),
        ];
    }

    public function onUnknownSheet($sheetName): void
    {
        // Sheet not present in this upload — skip silently
    }
}
