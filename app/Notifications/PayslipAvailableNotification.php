<?php

namespace App\Notifications;

use App\Models\Payroll\Payslip;
use App\Models\UserNotificationPreference;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** A payslip is ready. A link, not an attachment: pay details stay behind the login. */
class PayslipAvailableNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(private readonly Payslip $payslip) {}

    public function via($notifiable): array
    {
        return UserNotificationPreference::channelsFor($notifiable, 'payslip_available', ['mail']);
    }

    public function toMail($notifiable): MailMessage
    {
        $month = \Illuminate\Support\Carbon::create($this->payslip->run->year, $this->payslip->run->month)->format('F Y');

        return (new MailMessage)
            ->subject("Your payslip for {$month}")
            ->greeting("Dear {$notifiable->name},")
            ->line("Your payslip for {$month} is ready.")
            ->action('View payslip', env('FRONTEND_URL') . '/self-service/payslips');
    }

    public function toArray($notifiable): array
    {
        return ['type' => 'payslip_available', 'payslip_uuid' => $this->payslip->uuid, 'year' => $this->payslip->run->year, 'month' => $this->payslip->run->month];
    }
}
