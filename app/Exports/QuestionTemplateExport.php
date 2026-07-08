<?php

namespace App\Exports;

use App\Exports\Sheets\TemplateSheet;
use Maatwebsite\Excel\Concerns\WithMultipleSheets;

class QuestionTemplateExport implements WithMultipleSheets
{
    public function sheets(): array
    {
        return [
            // ── Multiple Choice ───────────────────────────────────────────────
            new TemplateSheet(
                'Multiple Choice',
                [
                    'Category', 'Question Text', 'Description', 'Weight', 'Is Required',
                    'Option 1 Text', 'Option 1 Value',
                    'Option 2 Text', 'Option 2 Value',
                    'Option 3 Text', 'Option 3 Value',
                    'Option 4 Text', 'Option 4 Value',
                    'Option 5 Text', 'Option 5 Value',
                ],
                [
                    [
                        'Skills', 'Which competency best describes your strength?', '', '1.00', 'no',
                        'Communication', '1', 'Leadership', '2', 'Technical', '3', 'Creativity', '4', 'Teamwork', '5',
                    ],
                ],
            ),

            // ── Rating (custom labels supported) ─────────────────────────────
            new TemplateSheet(
                'Rating',
                [
                    'Category', 'Question Text', 'Description', 'Weight', 'Is Required',
                    'Scale 1 Label', 'Scale 1 Value',
                    'Scale 2 Label', 'Scale 2 Value',
                    'Scale 3 Label', 'Scale 3 Value',
                    'Scale 4 Label', 'Scale 4 Value',
                    'Scale 5 Label', 'Scale 5 Value',
                ],
                [
                    // Default scale
                    [
                        'Performance', 'How would you rate your overall performance this period?',
                        'Leave scale columns blank to use defaults (Poor/Fair/Good/Very Good/Excellent)',
                        '1.00', 'yes',
                        'Poor', '1', 'Fair', '2', 'Good', '3', 'Very Good', '4', 'Excellent', '5',
                    ],
                    // Custom scale example
                    [
                        'Conduct', 'How consistently did you demonstrate professional conduct?',
                        'Custom frequency scale',
                        '1.00', 'yes',
                        'Never', '0', 'Rarely', '1', 'Sometimes', '2', 'Usually', '3', 'Always', '4',
                    ],
                ],
            ),

            // ── Yes / No (customisable labels and values) ─────────────────────
            new TemplateSheet(
                'Yes or No',
                [
                    'Category', 'Question Text', 'Description', 'Weight', 'Is Required',
                    'Yes Label', 'Yes Value', 'No Label', 'No Value',
                ],
                [
                    [
                        'General', 'Did you achieve your set targets this period?',
                        'Leave label/value columns blank to use defaults (Yes=1, No=0)',
                        '1.00', 'yes',
                        'Yes', '1', 'No', '0',
                    ],
                ],
            ),

            // ── Open Text ────────────────────────────────────────────────────
            new TemplateSheet(
                'Open Text',
                ['Category', 'Question Text', 'Description', 'Weight', 'Is Required'],
                [
                    ['General', 'Describe your key achievements this period.', 'Free-form response', '1.00', 'no'],
                ],
            ),
        ];
    }
}
