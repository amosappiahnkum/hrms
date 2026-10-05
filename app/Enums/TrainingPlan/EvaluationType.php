<?php

namespace App\Enums\TrainingPlan;

/** The two evaluations of a completed training (SOP 5.3.6: effectiveness of development). */
enum EvaluationType: string
{
    /** The trainee, soon after: how the training went. */
    case PARTICIPANT_FEEDBACK = 'participant_feedback';
    /** Their supervisor, months later: is it being applied on the job? */
    case SUPERVISOR_REVIEW = 'supervisor_review';

    public function label(): string
    {
        return match ($this) {
            self::PARTICIPANT_FEEDBACK => 'Participant feedback',
            self::SUPERVISOR_REVIEW    => 'Supervisor review',
        };
    }

    /** What `rating` (1–5) measures. */
    public function ratingQuestion(): string
    {
        return match ($this) {
            self::PARTICIPANT_FEEDBACK => 'Overall, how useful was the training?',
            self::SUPERVISOR_REVIEW    => 'How much has their performance in this area improved?',
        };
    }

    /** Further 1–5 questions, stored in `answers`. */
    public function questions(): array
    {
        return match ($this) {
            self::PARTICIPANT_FEEDBACK => [
                'objectives' => 'The training met its stated objectives',
                'relevance'  => 'The content was relevant to my job',
                'trainer'    => 'The trainer was knowledgeable and clear',
                'materials'  => 'The materials and facilities were adequate',
            ],
            self::SUPERVISOR_REVIEW => [],
        };
    }
}
