<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class EmailLinkedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    private string $name;

    /**
     * Create a new notification instance.
     */
    public function __construct(string $name)
    {
        $this->name = $name;
    }

    /**
     * Get the notification's delivery channels.
     *
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    /**
     * Get the mail representation of the notification.
     */
    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Account Linked')
            ->cc(['itsupport@ttu.edu.gh'])
            ->greeting('Dear ' . $this->name . '!')
            ->line('Your account has been linked successfully.')
            ->line('Visit hrms.ttuportal.com.')
            ->line('Click "Login with Google" and follow the prompt to log in and update your profile.');
    }

    /**
     * Get the array representation of the notification.
     *
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            //
        ];
    }
}
