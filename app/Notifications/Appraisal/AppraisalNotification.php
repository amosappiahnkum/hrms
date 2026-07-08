<?php

namespace App\Notifications\Appraisal;

use App\Models\UserNotificationPreference;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class AppraisalNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        private readonly string $type,
        private readonly string $subject,
        private readonly string $greeting,
        private readonly array  $lines,
    ) {}

    public function via(mixed $notifiable): array
    {
        $channels = UserNotificationPreference::channelsFor($notifiable, $this->type, ['mail']);

        return array_values(array_filter($channels, fn ($c) => $c !== 'database'));
    }

    public function toMail(mixed $notifiable): MailMessage
    {
        $mail = (new MailMessage)
            ->subject($this->subject)
            ->greeting($this->greeting);

        foreach ($this->lines as $line) {
            $mail->line($line);
        }

        return $mail->action('Open HRMS', env('FRONTEND_URL', config('app.url')));
    }
}
