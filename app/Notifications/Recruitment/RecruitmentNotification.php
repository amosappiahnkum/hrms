<?php

namespace App\Notifications\Recruitment;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class RecruitmentNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(private readonly array $data) {}

    public function via($notifiable): array
    {
        return ['mail'];
    }

    public function toMail($notifiable): MailMessage
    {
        $mail = (new MailMessage)
            ->subject($this->data['subject'] ?? 'Recruitment Update')
            ->greeting($this->data['greeting'] ?? 'Hello,');

        foreach (array_filter($this->data['lines'] ?? []) as $line) {
            $mail->line($line);
        }

        if (!empty($this->data['action_url'])) {
            $mail->action($this->data['action_text'] ?? 'View Details', $this->data['action_url']);
        }

        return $mail;
    }

    public function toArray($notifiable): array
    {
        return [];
    }
}
