<?php

namespace App\Notifications;

use App\Models\TrainingPlan\TrainingPlan;
use App\Models\UserNotificationPreference;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** HR opened a plan's collection window: heads of department add the trainings their staff need. */
class TrainingPlanCollectionNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(private readonly TrainingPlan $plan) {}

    public function via($notifiable): array
    {
        return UserNotificationPreference::channelsFor($notifiable, 'training_plan_collection', ['mail']);
    }

    public function toMail($notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject("{$this->plan->year} training plan: add your team's training needs")
            ->greeting("Dear {$notifiable->name},")
            ->line($this->summary())
            ->line('HR will review what you add before the plan goes for approval.')
            ->action('Add training needs', env('FRONTEND_URL') . '/self-service/team-training');
    }

    public function toArray($notifiable): array
    {
        return [
            'type'        => 'training_plan_collection',
            'plan_uuid'   => $this->plan->uuid,
            'description' => $this->summary(),
        ];
    }

    private function summary(): string
    {
        $until = $this->plan->collection_ends_on->format('j M Y');

        return "The {$this->plan->year} training plan is open for your department's training needs until {$until}. Add the trainings your staff require.";
    }
}
