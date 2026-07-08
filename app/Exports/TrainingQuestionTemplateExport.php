<?php

namespace App\Exports;

use App\Exports\Sheets\TemplateSheet;
use Maatwebsite\Excel\Concerns\WithMultipleSheets;

class TrainingQuestionTemplateExport implements WithMultipleSheets
{
    public function sheets(): array
    {
        return [
            // ── Multiple Choice ───────────────────────────────────────────────
            new TemplateSheet(
                'Multiple Choice',
                [
                    'Question Text', 'Category', 'Description', 'Weight', 'Is Required',
                    'Option 1', 'Option 2', 'Option 3', 'Option 4', 'Option 5',
                    'Correct Option',
                ],
                [
                    ['What is the capital of France?',  'Geography',     '',              '1', 'yes', 'Berlin', 'Paris',               'London',          'Rome', '',  '2'],
                    ['Which item is a fire exit sign?', 'Safety Basics', 'Safety basics', '1', 'yes', 'Green running figure', 'Red circle', 'Yellow triangle', '',   '',  '1'],
                ],
            ),

            // ── Rating (custom labels — value = score for that level) ─────────
            new TemplateSheet(
                'Rating',
                [
                    'Question Text', 'Category', 'Description', 'Weight', 'Is Required',
                    'Scale 1 Label', 'Scale 1 Value',
                    'Scale 2 Label', 'Scale 2 Value',
                    'Scale 3 Label', 'Scale 3 Value',
                    'Scale 4 Label', 'Scale 4 Value',
                    'Scale 5 Label', 'Scale 5 Value',
                ],
                [
                    [
                        'How well did the training explain the safety procedures?', 'Safety Basics', '', '5', 'yes',
                        'Poor', '1', 'Fair', '2', 'Good', '3', 'Very Good', '4', 'Excellent', '5',
                    ],
                ],
            ),

            // ── Yes / No (Correct Answer = Yes or No) ────────────────────────
            new TemplateSheet(
                'Yes or No',
                ['Question Text', 'Category', 'Description', 'Weight', 'Is Required', 'Correct Answer'],
                [
                    ['Is it safe to handle chemicals without PPE?', 'Safety Basics', '',  '1', 'yes', 'No'],
                    ['Should you report a spill immediately?',       'Safety Basics', '',  '1', 'yes', 'Yes'],
                ],
            ),

            // ── Open Text ────────────────────────────────────────────────────
            new TemplateSheet(
                'Open Text',
                ['Question Text', 'Category', 'Description', 'Weight', 'Is Required'],
                [
                    ['Describe the steps you would take in a chemical spill.', 'Safety Basics', '', '0', 'no'],
                ],
            ),
        ];
    }
}
