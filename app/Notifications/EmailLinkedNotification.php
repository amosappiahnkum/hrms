<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class EmailLinkedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        private string  $name,
        private string  $email,
        private ?string $password = null,
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new MailMessage)
            ->subject('Your Account Has Been Created')
            ->cc([config('app.support_mail')])
            ->greeting("Dear {$this->name},")
            ->line('Your HRMS account has been created successfully.');

        if ($this->password) {
            $mail->line('Use the credentials below to log in for the first time:')
                 ->line("**Email:** {$this->email}")
                 ->line("**Temporary Password:** {$this->password}")
                 ->line('Please change your password immediately after your first login.');
        }

        return $mail->action('Log In Now', config('app.url'));
    }

    public function toArray(object $notifiable): array
    {
        return [];
    }
}
