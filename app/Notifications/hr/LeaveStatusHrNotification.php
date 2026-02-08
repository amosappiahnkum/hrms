<?php

namespace App\Notifications\hr;

use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class LeaveStatusHrNotification extends Notification implements ShouldQueue
{
    use Queueable;

    private $data;

    /**
     * Create a new notification instance.
     *
     * @return void
     */
    public function __construct($data)
    {
        $this->data = $data;
    }

    /**
     * Get the notification's delivery channels.
     *
     * @param mixed $notifiable
     * @return array
     */
    public function via($notifiable)
    {
        return ['mail'];
    }

    /**
     * Get the mail representation of the notification.
     *
     * @param mixed $notifiable
     * @return MailMessage `MailMessage
     */
    public function toMail($notifiable): MailMessage
    {
        $mail = (new MailMessage)
            ->subject($this->data['subject'] ?? '')
            ->greeting($this->data['greeting'] ?? '');

        foreach ($this->data['lines'] ?? [] as $line) {
            $mail->line($line);
        }

        $mail->action('Review Request', env('FRONTEND_URL'));

        return $mail;
        /*$status = strtoupper($this->data['leaveStatus']);

        $mail = (new MailMessage);

        $mail->greeting('Dear ' . $this->data['hr'] . ',')->subject('Leave Request Notification');

        if ($status == 'PENDING') {
            $mail->line('Please note that ' . $this->data['employee'] . ' has submitted a leave request, which is currently pending action from the Head of Department');
        } else if ($status == 'AUTO_APPROVED') {
            $mail->line('Please note that ' . $this->data['employee'] . ' has submitted a leave request, which is currently pending your action');
        } else {
            $mail->line($this->data['supervisor'] . ' ' . $status . ' a leave request for ' . $this->data['employee'] . ' on ' .
                Carbon::parse($this->data['date'])->format('D, M d Y') . ' and it\'s pending your action.');
        }

        $mail->action('Review Request', env('FRONTEND_URL'));
        return $mail;*/
    }

    /**
     * Get the array representation of the notification.
     *
     * @param mixed $notifiable
     * @return array
     */
    public function toArray($notifiable)
    {
        return [
            //
        ];
    }
}
