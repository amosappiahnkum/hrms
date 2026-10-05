<?php

namespace App\Notifications;

use App\Enums\TrainingPlan\EvaluationType;
use App\Models\TrainingPlan\TrainingEvaluation;
use App\Models\UserNotificationPreference;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** A completed training is waiting for the recipient's feedback or review. */
class TrainingEvaluationNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        private readonly TrainingEvaluation $evaluation,
        private readonly bool $reminder = false,
    ) {}

    public function via($notifiable): array
    {
        return UserNotificationPreference::channelsFor($notifiable, 'training_evaluation', ['mail']);
    }

    public function toMail($notifiable): MailMessage
    {
        $item = $this->evaluation->item;
        $who = $item->employee?->name;
        $feedback = $this->evaluation->type === EvaluationType::PARTICIPANT_FEEDBACK;

        $lead = $feedback
            ? "Thank you for completing **{$item->title}**. Please tell us how it went: it takes a minute and helps us plan better training."
            : "**{$who}** completed **{$item->title}** on " . ($item->completed_at?->format('F j, Y') ?? 'a recent date')
                . '. Please review whether they are applying it on the job.';

        return (new MailMessage)
            ->subject($this->subject())
            ->greeting("Dear {$notifiable->name},")
            ->line($lead)
            ->line('Please respond by ' . $this->evaluation->due_on->format('F j, Y') . '.')
            ->action($feedback ? 'Give feedback' : 'Review training', env('FRONTEND_URL') . '/self-service/training?view=planned');
    }

    public function toArray($notifiable): array
    {
        return [
            'type'            => 'training_evaluation',
            'evaluation_uuid' => $this->evaluation->uuid,
            'evaluation_type' => $this->evaluation->type->value,
            'item_uuid'       => $this->evaluation->item?->uuid,
            'title'           => $this->evaluation->item?->title,
            'employee_name'   => $this->evaluation->item?->employee?->name,
            'due_on'          => $this->evaluation->due_on->format('Y-m-d'),
            'reminder'        => $this->reminder,
        ];
    }

    private function subject(): string
    {
        $item = $this->evaluation->item;
        $prefix = $this->reminder ? 'Reminder: ' : '';

        return $this->evaluation->type === EvaluationType::PARTICIPANT_FEEDBACK
            ? "{$prefix}How was \"{$item->title}\"?"
            : "{$prefix}Review {$item->employee?->name}'s training \"{$item->title}\"";
    }
}
