<?php

namespace App\Notifications;

use App\Models\UserNotificationPreference;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class LeaveResumptionReminderNotification extends Notification implements ShouldQueue
{
    use Queueable;

    private array $data;

    public function __construct(array $data)
    {
        $this->data = $data;
    }

    public function via($notifiable): array
    {
        return UserNotificationPreference::channelsFor($notifiable, 'leave_resumption_reminder', ['mail']);
    }

    public function toMail($notifiable): MailMessage
    {
        $mail = (new MailMessage)
            ->subject($this->data['subject'] ?? '')
            ->greeting($this->data['greeting'] ?? '');

        foreach ($this->data['lines'] ?? [] as $line) {
            $mail->line($line);
        }

        $mail->action('View Leave Requests', env('FRONTEND_URL'));

        return $mail;
    }

    public function toArray($notifiable): array
    {
        return [];
    }
}
