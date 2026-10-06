<?php

namespace App\Notifications;

use App\Contracts\ApprovalSubject;
use App\Models\Payroll\Approval;
use App\Models\UserNotificationPreference;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** The recipient's request (overtime, loan…) has been approved or rejected. */
class ApprovalDecidedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(private readonly Approval $approval, private readonly string $link) {}

    public function via($notifiable): array
    {
        return UserNotificationPreference::channelsFor($notifiable, 'payroll_decided', ['mail']);
    }

    public function toMail($notifiable): MailMessage
    {
        $subject = $this->approval->subject;
        $summary = $subject instanceof ApprovalSubject ? $subject->approvalSummary() : $this->approval->process->label();
        $approved = $this->approval->status === Approval::APPROVED;
        $comment = $this->approval->decisions()->latest('id')->value('comment');

        $mail = (new MailMessage)
            ->subject(($approved ? 'Approved: ' : 'Not approved: ') . $this->approval->process->label())
            ->greeting("Dear {$notifiable->name},")
            ->line($summary)
            ->line($approved ? 'Your request has been approved.' : 'Your request was not approved.');
        if ($comment) {
            $mail->line("Comment: {$comment}");
        }

        return $mail->action('View', env('FRONTEND_URL') . $this->link);
    }

    public function toArray($notifiable): array
    {
        return [
            'type'          => 'payroll_decided',
            'approval_uuid' => $this->approval->uuid,
            'process'       => $this->approval->process->value,
            'status'        => $this->approval->status,
        ];
    }
}
