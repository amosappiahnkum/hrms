<?php

namespace App\Notifications;

use App\Contracts\ApprovalSubject;
use App\Models\Payroll\Approval;
use App\Models\UserNotificationPreference;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** A request that was waiting for (or already approved by) the recipient was taken back by the employee to correct. */
class ApprovalWithdrawnNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(private readonly Approval $approval) {}

    public function via($notifiable): array
    {
        return UserNotificationPreference::channelsFor($notifiable, 'payroll_approval', ['mail']);
    }

    public function toMail($notifiable): MailMessage
    {
        $subject = $this->approval->subject;
        $summary = $subject instanceof ApprovalSubject ? $subject->approvalSummary() : $this->approval->process->label();
        $who = $this->approval->employee?->name ?? 'The employee';

        return (new MailMessage)
            ->subject("Taken back: {$this->approval->process->label()} ({$who})")
            ->greeting("Dear {$notifiable->name},")
            ->line("**{$who}** took this back to correct it: {$summary}")
            ->line("There's nothing for you to do now. Once it's sent again it comes back to you for approval.");
    }

    public function toArray($notifiable): array
    {
        return [
            'type'          => 'payroll_withdrawn',
            'approval_uuid' => $this->approval->uuid,
            'process'       => $this->approval->process->value,
            'employee_name' => $this->approval->employee?->name,
        ];
    }
}
