<?php

namespace App\Imports;

use App\Enums\QuestionType;
use App\Models\QuestionBank\Question;
use App\Models\QuestionBank\QuestionCategory;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Maatwebsite\Excel\Concerns\SkipsEmptyRows;
use Maatwebsite\Excel\Concerns\ToCollection;
use Maatwebsite\Excel\Concerns\WithHeadingRow;

/**
 * Handles one sheet (one question type) from a multi-sheet import.
 * Writes imported count and errors back to the parent import object.
 */
class QuestionSheetImport implements ToCollection, WithHeadingRow, SkipsEmptyRows
{
    /**
     * @param QuestionType        $type     The type all rows on this sheet represent
     * @param string              $scope    'appraisal' | 'training'
     * @param object              $parent   Parent import instance (holds public $imported and $errors)
     * @param int|null            $courseId Required when scope = 'training'
     */
    public function __construct(
        private readonly QuestionType $type,
        private readonly string $scope,
        private readonly object $parent,
        private readonly ?int $courseId = null,
    ) {}

    public function collection(Collection $rows): void
    {
        foreach ($rows as $index => $row) {
            $rowNum = $index + 2;
            $label  = "[{$this->type->label()} | Row {$rowNum}]";

            $text = trim($row['question_text'] ?? '');
            if ($text === '') {
                $this->parent->errors[] = "{$label}: \"Question Text\" is required.";
                continue;
            }

            if (!$this->validateRow($row, $rowNum, $label)) {
                continue;
            }

            try {
                DB::transaction(function () use ($row, $text) {
                    $weight     = is_numeric($row['weight'] ?? null) ? (float) $row['weight'] : 1.00;
                    $isRequired = in_array(strtolower(trim($row['is_required'] ?? '')), ['yes', 'true', '1'], true);

                    $attributes = [
                        'type'        => $this->type,
                        'text'        => $text,
                        'description' => trim($row['description'] ?? '') ?: null,
                        'weight'      => $weight,
                        'is_required' => $isRequired,
                        'is_active'   => true,
                    ];

                    if ($this->scope === 'training') {
                        $attributes['scope']     = 'training';
                        $attributes['course_id'] = $this->courseId;
                    }

                    $categoryName = trim($row['category'] ?? '');
                    if ($categoryName !== '') {
                        $category = QuestionCategory::firstOrCreate(
                            ['name' => $categoryName],
                            ['uuid' => Str::uuid()],
                        );
                        $attributes['question_category_id'] = $category->id;
                    }

                    $question = Question::create($attributes);
                    $this->createOptions($question, $row, $weight);
                    $this->parent->imported++;
                });
            } catch (\Throwable $e) {
                $this->parent->errors[] = "[{$this->type->label()} | Row {$rowNum}]: " . $e->getMessage();
            }
        }
    }

    // ── Validation ────────────────────────────────────────────────────────────

    private function validateRow($row, int $rowNum, string $label): bool
    {
        if ($this->scope === 'training' && $this->type === QuestionType::MULTIPLE_CHOICE) {
            $correct = trim($row['correct_option'] ?? '');
            if (!is_numeric($correct) || (int) $correct < 1 || (int) $correct > 5) {
                $this->parent->errors[] = "{$label}: \"Correct Option\" must be a number between 1 and 5.";
                return false;
            }
        }

        if ($this->scope === 'training' && $this->type === QuestionType::YES_NO) {
            $ans = strtolower(trim($row['correct_answer'] ?? ''));
            if (!in_array($ans, ['yes', 'no'], true)) {
                $this->parent->errors[] = "{$label}: \"Correct Answer\" must be Yes or No.";
                return false;
            }
        }

        return true;
    }

    // ── Option creation (dispatches to scope + type) ──────────────────────────

    private function createOptions(Question $question, $row, float $weight): void
    {
        if ($this->scope === 'training') {
            $this->trainingOptions($question, $row, $weight);
        } else {
            $this->appraisalOptions($question, $row);
        }
    }

    private function appraisalOptions(Question $question, $row): void
    {
        switch ($this->type) {
            case QuestionType::MULTIPLE_CHOICE:
                $order = 1;
                for ($i = 1; $i <= 5; $i++) {
                    $text  = trim($row["option_{$i}_text"]  ?? '');
                    $value = trim($row["option_{$i}_value"] ?? '');
                    if ($text !== '') {
                        $question->options()->create([
                            'option_text'  => $text,
                            'option_value' => is_numeric($value) ? $value : (string) $order,
                            'order'        => $order++,
                        ]);
                    }
                }
                break;

            case QuestionType::RATING:
                if (!empty(trim($row['scale_1_label'] ?? ''))) {
                    // Custom scale
                    $order = 1;
                    for ($i = 1; $i <= 5; $i++) {
                        $scaleLabel = trim($row["scale_{$i}_label"] ?? '');
                        $scaleValue = trim($row["scale_{$i}_value"] ?? '');
                        if ($scaleLabel !== '') {
                            $question->options()->create([
                                'option_text'  => $scaleLabel,
                                'option_value' => is_numeric($scaleValue) ? $scaleValue : (string) $i,
                                'order'        => $order++,
                            ]);
                        }
                    }
                } else {
                    // Use enum defaults (Poor–Excellent, values 1–5)
                    foreach (QuestionType::RATING->defaultOptions() as $opt) {
                        $question->options()->create($opt);
                    }
                }
                break;

            case QuestionType::YES_NO:
                $yesLabel = trim($row['yes_label'] ?? '') ?: 'Yes';
                $yesValue = trim($row['yes_value'] ?? '');
                $noLabel  = trim($row['no_label']  ?? '') ?: 'No';
                $noValue  = trim($row['no_value']  ?? '');

                $question->options()->create([
                    'option_text'  => $yesLabel,
                    'option_value' => is_numeric($yesValue) ? $yesValue : '1',
                    'order'        => 1,
                ]);
                $question->options()->create([
                    'option_text'  => $noLabel,
                    'option_value' => is_numeric($noValue) ? $noValue : '0',
                    'order'        => 2,
                ]);
                break;

            case QuestionType::OPEN_TEXT:
                // No options
                break;
        }
    }

    private function trainingOptions(Question $question, $row, float $weight): void
    {
        switch ($this->type) {
            case QuestionType::MULTIPLE_CHOICE:
                $correctIndex = (int) trim($row['correct_option'] ?? '0');
                $order = 1;
                for ($i = 1; $i <= 5; $i++) {
                    $text = trim($row["option_{$i}"] ?? '');
                    if ($text !== '') {
                        $question->options()->create([
                            'option_text'  => $text,
                            'option_value' => ($i === $correctIndex) ? (string) $weight : '0',
                            'order'        => $order++,
                        ]);
                    }
                }
                break;

            case QuestionType::RATING:
                // Training rating: value = score for that level (same column structure as appraisal rating)
                if (!empty(trim($row['scale_1_label'] ?? ''))) {
                    $order = 1;
                    for ($i = 1; $i <= 5; $i++) {
                        $scaleLabel = trim($row["scale_{$i}_label"] ?? '');
                        $scaleValue = trim($row["scale_{$i}_value"] ?? '');
                        if ($scaleLabel !== '') {
                            $question->options()->create([
                                'option_text'  => $scaleLabel,
                                'option_value' => is_numeric($scaleValue) ? $scaleValue : (string) $i,
                                'order'        => $order++,
                            ]);
                        }
                    }
                } else {
                    foreach (QuestionType::RATING->defaultOptions() as $opt) {
                        $question->options()->create($opt);
                    }
                }
                break;

            case QuestionType::YES_NO:
                // Correct Answer column determines which option gets full marks
                $yesIsCorrect = in_array(
                    strtolower(trim($row['correct_answer'] ?? '')),
                    ['yes', '1', 'true'],
                    true,
                );
                $question->options()->create([
                    'option_text'  => 'Yes',
                    'option_value' => $yesIsCorrect ? (string) $weight : '0',
                    'order'        => 1,
                ]);
                $question->options()->create([
                    'option_text'  => 'No',
                    'option_value' => !$yesIsCorrect ? (string) $weight : '0',
                    'order'        => 2,
                ]);
                break;

            case QuestionType::OPEN_TEXT:
                // No options
                break;
        }
    }
}
