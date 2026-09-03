<?php

namespace App\Notifications;

use App\Models\EmployeeCertification;
use App\Models\UserNotificationPreference;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class CertificationExpiryNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        private readonly EmployeeCertification $certification,
        private readonly int $daysRemaining,
        private readonly string $recipientType, // 'employee' | 'hr'
    ) {}

    public function via($notifiable): array
    {
        return UserNotificationPreference::channelsFor($notifiable, 'certification_expiry_reminder', ['mail']);
    }

    public function toMail($notifiable): MailMessage
    {
        $cert     = $this->certification;
        $employee = $cert->employee;
        $expiry   = $cert->expiry_date->format('l, F j, Y');
        $days     = $this->daysRemaining;
        $urgency  = $days <= 7 ? 'urgent' : ($days <= 30 ? 'soon' : 'upcoming');

        if ($this->recipientType === 'employee') {
            return (new MailMessage)
                ->subject($this->subjectLine())
                ->greeting("Dear {$employee->first_name},")
                ->line("Your certification **{$cert->title}** (issued by {$cert->provider?->name}) is expiring in **{$days} day(s)** on {$expiry}.")
                ->line($this->urgencyLine($urgency))
                ->action('View My Certifications', env('FRONTEND_URL') . '/self-service/certifications')
                ->line('Please take action before the expiry date to ensure continued compliance.');
        }

        return (new MailMessage)
            ->subject($this->subjectLine())
            ->greeting("Dear HR Team,")
            ->line("The following certification is expiring in **{$days} day(s)** on {$expiry}:")
            ->line("**Employee:** {$employee->name} ({$employee->staff_id})")
            ->line("**Certification:** {$cert->title}")
            ->line("**Issued By:** {$cert->provider?->name}")
            ->line($this->urgencyLine($urgency))
            ->action('View Certifications', env('FRONTEND_URL') . '/employee-management/certifications')
            ->line('Please follow up with the employee to arrange renewal.');
    }

    public function toArray($notifiable): array
    {
        $cert = $this->certification;

        return [
            'type'            => 'certification_expiry_reminder',
            'certification_id' => $cert->id,
            'title'           => $cert->title,
            'employee_name'   => $cert->employee?->name,
            'employee_id'     => $cert->employee?->staff_id,
            'days_remaining'  => $this->daysRemaining,
            'expiry_date'     => $cert->expiry_date?->format('Y-m-d'),
            'recipient_type'  => $this->recipientType,
        ];
    }

    private function subjectLine(): string
    {
        $cert = $this->certification;
        $days = $this->daysRemaining;

        if ($this->recipientType === 'employee') {
            return match (true) {
                $days <= 7  => "⚠️ Urgent: Your certification \"{$cert->title}\" expires in {$days} day(s)",
                $days <= 30 => "Action Required: Certification \"{$cert->title}\" expiring soon",
                default     => "Reminder: Certification \"{$cert->title}\" expires in {$days} days",
            };
        }

        $employee = $cert->employee;
        return match (true) {
            $days <= 7  => "⚠️ Urgent: {$employee->name}'s certification \"{$cert->title}\" expires in {$days} day(s)",
            $days <= 30 => "Action Required: {$employee->name}'s certification expiring soon",
            default     => "Reminder: {$employee->name}'s certification expires in {$days} days",
        };
    }

    private function urgencyLine(string $urgency): string
    {
        return match ($urgency) {
            'urgent' => 'This is an urgent reminder — the certification expires within the next 7 days.',
            'soon'   => 'Please make arrangements for renewal as the expiry is within the next month.',
            default  => 'You have time to plan renewal, but we recommend acting early.',
        };
    }
}
