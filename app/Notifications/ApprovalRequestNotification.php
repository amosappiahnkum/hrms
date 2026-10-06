<?php

namespace App\Notifications;

use App\Contracts\ApprovalSubject;
use App\Models\Payroll\Approval;
use App\Models\UserNotificationPreference;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** A payroll request (overtime, loan…) is waiting for the recipient's approval. */
class ApprovalRequestNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(private readonly Approval $approval, private readonly string $stepName) {}

    public function via($notifiable): array
    {
        return UserNotificationPreference::channelsFor($notifiable, 'payroll_approval', ['mail']);
    }

    public function toMail($notifiable): MailMessage
    {
        $subject = $this->approval->subject;
        $summary = $subject instanceof ApprovalSubject ? $subject->approvalSummary() : $this->approval->process->label();
        $who = $this->approval->employee?->name;

        return (new MailMessage)
            ->subject("Approval needed: {$this->approval->process->label()}" . ($who ? " ({$who})" : ''))
            ->greeting("Dear {$notifiable->name},")
            ->line(($who ? "**{$who}**: " : '') . $summary)
            ->line("It's waiting for your decision at the **{$this->stepName}** step.")
            ->action('Review', env('FRONTEND_URL') . ($this->approval->process === \App\Enums\Payroll\ApprovalProcess::PAY_RUN
                ? '/payroll/runs/' . $this->approval->subject?->uuid
                : '/self-service/approvals'));
    }

    public function toArray($notifiable): array
    {
        return [
            'type'          => 'payroll_approval',
            'approval_uuid' => $this->approval->uuid,
            'process'       => $this->approval->process->value,
            'step'          => $this->stepName,
            'employee_name' => $this->approval->employee?->name,
        ];
    }
}
