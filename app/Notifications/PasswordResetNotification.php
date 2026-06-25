<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class PasswordResetNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        private string $name,
        private string $email,
        private string $password,
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Your Password Has Been Reset')
            ->cc([config('app.support_mail')])
            ->greeting("Dear {$this->name},")
            ->line('Your HRMS account password has been reset by an administrator.')
            ->line('Use the credentials below to log in:')
            ->line("**Email:** {$this->email}")
            ->line("**Temporary Password:** {$this->password}")
            ->line('You will be required to change your password on your next login.')
            ->action('Log In Now', config('app.url'));
    }

    public function toArray(object $notifiable): array
    {
        return [];
    }
}
