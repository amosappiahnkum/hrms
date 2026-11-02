<?php

namespace App\Notifications;

use App\Models\Competency\DevelopmentAction;
use App\Models\UserNotificationPreference;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** A development action took place (e.g. its training was completed); its effectiveness needs checking. */
class DevelopmentActionEvaluationNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(private readonly DevelopmentAction $action) {}

    public function via($notifiable): array
    {
        return UserNotificationPreference::channelsFor($notifiable, 'competency_evaluation', ['mail']);
    }

    public function toMail($notifiable): MailMessage
    {
        $a = $this->action;
        $who = $a->employee?->name;

        return (new MailMessage)
            ->subject("Check the effectiveness of {$who}'s development in {$a->competency?->name}")
            ->greeting("Dear {$notifiable->name},")
            ->line("**{$who}** has completed the {$a->method->label()} planned to close their gap in **{$a->competency?->name}**.")
            ->line("Please re-rate the competency to check it worked. **How it's checked:** {$a->method->effectivenessCheck()}.")
            ->action('Evaluate', env('FRONTEND_URL') . $this->path($notifiable));
    }

    /** Organisation-wide access opens the competency matrix; team leaders open their team's page. */
    private function path($notifiable): string
    {
        return $notifiable->canAny(['view-competencies', 'assess-competencies', 'manage-competencies'])
            ? '/employee-management/competency?tab=gaps'
            : '/self-service/team-competencies?tab=gaps';
    }

    public function toArray($notifiable): array
    {
        return [
            'type'            => 'competency_evaluation',
            'action_uuid'     => $this->action->uuid,
            'employee_uuid'   => $this->action->employee?->uuid,
            'employee_name'   => $this->action->employee?->name,
            'competency_name' => $this->action->competency?->name,
            'method'          => $this->action->method->value,
        ];
    }
}
