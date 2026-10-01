<?php

namespace App\Notifications;

use App\Models\TrainingPlan\TrainingPlan;
use App\Models\User;
use App\Models\UserNotificationPreference;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** A training plan or item moved through prepare → validate → approve (or was rejected). */
class TrainingPlanApprovalNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        private readonly TrainingPlan $plan,
        private readonly string $event, // submitted | validated | approved | rejected
        private readonly User $actor,
        private readonly ?string $comment = null,
    ) {}

    public function via($notifiable): array
    {
        return UserNotificationPreference::channelsFor($notifiable, 'training_plan_approval', ['mail']);
    }

    public function toMail($notifiable): MailMessage
    {
        $mail = (new MailMessage)
            ->subject($this->subject())
            ->greeting("Dear {$notifiable->name},")
            ->line($this->summary());

        if ($this->comment) {
            $mail->line("**Comment:** {$this->comment}");
        }

        return $mail->action('Open Training Plan', env('FRONTEND_URL') . '/training/plans?year=' . $this->plan->year);
    }

    public function toArray($notifiable): array
    {
        return [
            'type'        => 'training_plan_approval',
            'event'       => $this->event,
            'plan_uuid'   => $this->plan->uuid,
            'description' => $this->summary(),
            'actor'       => $this->actor->name,
            'comment'     => $this->comment,
        ];
    }

    private function subject(): string
    {
        return match ($this->event) {
            'submitted' => "{$this->plan->year} training plan: validation required",
            'validated' => "{$this->plan->year} training plan: approval required",
            'approved'  => "{$this->plan->year} training plan approved",
            'rejected'  => "{$this->plan->year} training plan returned",
        };
    }

    private function summary(): string
    {
        $what = "the training plan \"{$this->plan->title}\"";

        return match ($this->event) {
            'submitted' => "{$this->actor->name} submitted {$what} and it needs your validation.",
            'validated' => "{$this->actor->name} validated {$what} and it needs your approval.",
            'approved'  => "{$this->actor->name} approved {$what}.",
            'rejected'  => "{$this->actor->name} returned {$what} with comments.",
        };
    }
}
