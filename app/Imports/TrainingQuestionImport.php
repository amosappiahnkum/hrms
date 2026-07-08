<?php

namespace App\Imports;

use App\Enums\QuestionType;
use App\Models\Training\Course;
use Maatwebsite\Excel\Concerns\Importable;
use Maatwebsite\Excel\Concerns\SkipsUnknownSheets;
use Maatwebsite\Excel\Concerns\WithMultipleSheets;

class TrainingQuestionImport implements WithMultipleSheets, SkipsUnknownSheets
{
    use Importable;

    public int $imported = 0;
    public array $errors = [];

    public function __construct(private readonly Course $course) {}

    /**
     * Keyed by sheet tab name so missing tabs are skipped gracefully.
     * Names must match the titles set in TrainingQuestionTemplateExport.
     */
    public function sheets(): array
    {
        return [
            'Multiple Choice' => new QuestionSheetImport(QuestionType::MULTIPLE_CHOICE, 'training', $this, $this->course->id),
            'Rating'          => new QuestionSheetImport(QuestionType::RATING,           'training', $this, $this->course->id),
            'Yes or No'       => new QuestionSheetImport(QuestionType::YES_NO,           'training', $this, $this->course->id),
            'Open Text'       => new QuestionSheetImport(QuestionType::OPEN_TEXT,        'training', $this, $this->course->id),
        ];
    }

    public function onUnknownSheet($sheetName): void
    {
        // Sheet not present in this upload — skip silently
    }
}
