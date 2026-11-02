<?php

namespace App\Notifications;

use App\Models\TrainingPlan\TrainingPlanItem;
use App\Models\UserNotificationPreference;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** A planned training is coming up (or is past and still needs its status updated). */
class TrainingPlanReminderNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        private readonly TrainingPlanItem $item,
        private readonly int $daysUntilStart,          // negative for overdue follow-ups
        private readonly string $recipientType,        // trainee | hod | hr
    ) {}

    public function via($notifiable): array
    {
        return UserNotificationPreference::channelsFor($notifiable, 'training_plan_reminder', ['mail']);
    }

    public function isOverdue(): bool
    {
        return $this->daysUntilStart < 0;
    }

    public function toMail($notifiable): MailMessage
    {
        $item = $this->item;
        $start = $item->planned_start_date?->format('l, F j, Y');
        $end = $item->planned_end_date?->format('l, F j, Y');
        $who = $item->employee?->name;

        $mail = (new MailMessage)->subject($this->subject())->greeting("Dear {$notifiable->name},");

        if ($this->isOverdue()) {
            return $mail
                ->line("The planned training **{$item->title}** for {$who} ended on " . ($end ?? $start) . ", but its status is still **{$item->status->label()}**.")
                ->line('Please update its status (completed, failed, postponed or cancelled) and upload the certificate if one was issued.')
                ->action('Open Training Plan', env('FRONTEND_URL') . '/training/plans?year=' . $item->plan->year . '&tab=trainings');
        }

        $lead = $this->recipientType === 'trainee'
            ? "Your training **{$item->title}** starts in **{$this->daysUntilStart} day(s)**, on {$start}."
            : "**{$who}**'s training **{$item->title}** starts in **{$this->daysUntilStart} day(s)**, on {$start}.";

        return $mail
            ->line($lead)
            ->line('**Trainer:** ' . ($item->trainer ?: '—') . ($end ? "  \n**Ends:** {$end}" : ''))
            ->action('View Training', env('FRONTEND_URL') . ($this->recipientType === 'trainee' ? '/self-service' : '/training/plans?year=' . $item->plan->year . '&tab=trainings'));
    }

    public function toArray($notifiable): array
    {
        return [
            'type'             => 'training_plan_reminder',
            'item_uuid'        => $this->item->uuid,
            'plan_uuid'        => $this->item->plan?->uuid,
            'title'            => $this->item->title,
            'employee_name'    => $this->item->employee?->name,
            'start_date'       => $this->item->planned_start_date?->format('Y-m-d'),
            'days_until_start' => $this->daysUntilStart,
            'overdue'          => $this->isOverdue(),
            'recipient_type'   => $this->recipientType,
        ];
    }

    private function subject(): string
    {
        if ($this->isOverdue()) {
            return "Training status needed: {$this->item->title} ({$this->item->employee?->name})";
        }

        return $this->recipientType === 'trainee'
            ? "Reminder: your training \"{$this->item->title}\" starts in {$this->daysUntilStart} day(s)"
            : "Reminder: {$this->item->employee?->name}'s training \"{$this->item->title}\" starts in {$this->daysUntilStart} day(s)";
    }
}
