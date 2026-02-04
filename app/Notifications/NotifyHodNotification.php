<?php

namespace App\Notifications;

use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class NotifyHodNotification extends Notification implements ShouldQueue
{
    use Queueable;

    private array $data;

    /**
     * Create a new notification instance.
     *
     * @return void
     */
    public function __construct(array $data)
    {
        $this->data = $data;
    }

    /**
     * Get the notification's delivery channels.
     *
     * @param mixed $notifiable
     * @return array
     */
    public function via($notifiable): array
    {
        return ['mail'];
    }

    /**
     * Get the mail representation of the notification.
     *
     * @param mixed $notifiable
     * @return MailMessage
     */
    public function toMail($notifiable): MailMessage
    {
        $status = strtoupper($this->data['leaveStatus']);

        $pendingText = $status == 'APPROVED' ? ' and it\'s pending HR approval.' : '';

        return (new MailMessage)
            ->greeting('Dear ' . $this->data['supervisor'] . ',')
            ->subject('Leave Request ' . $status)
            ->line('You ' . $status . ' a leave request for ' . $this->data['employee'])
            ->line('on ' . Carbon::parse($this->data['date'])->format('D, M d Y'))
            ->line($pendingText)
            ->action('Review Request', env('FRONTEND_URL'));
    }

    /**
     * Get the array representation of the notification.
     *
     * @param mixed $notifiable
     * @return array
     */
    public function toArray($notifiable): array
    {
        return [
            //
        ];
    }
}
