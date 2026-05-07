<?php

namespace App\Enums;

enum QuestionType: string
{
    case RATING = 'rating';
    case YES_NO = 'yes_no';
    case MULTIPLE_CHOICE = 'multiple_choice';
    case OPEN_TEXT = 'open_text';

    public function label(): string
    {
        return match($this) {
            self::RATING => 'Rating (1-5)',
            self::YES_NO => 'Yes/No',
            self::MULTIPLE_CHOICE => 'Multiple Choice',
            self::OPEN_TEXT => 'Open-Ended Text',
        };
    }

    public function requiresOptions(): bool
    {
        return match($this) {
            self::MULTIPLE_CHOICE => true,
            default => false,
        };
    }

    public function defaultOptions(): array
    {
        return match($this) {
            self::RATING => [
                ['option_text' => '1 - Poor', 'option_value' => '1', 'order' => 1],
                ['option_text' => '2 - Fair', 'option_value' => '2', 'order' => 2],
                ['option_text' => '3 - Good', 'option_value' => '3', 'order' => 3],
                ['option_text' => '4 - Very Good', 'option_value' => '4', 'order' => 4],
                ['option_text' => '5 - Excellent', 'option_value' => '5', 'order' => 5],
            ],
            self::YES_NO => [
                ['option_text' => 'Yes', 'option_value' => '1', 'order' => 1],
                ['option_text' => 'No', 'option_value' => '0', 'order' => 2],
            ],
            default => [],
        };
    }
}
