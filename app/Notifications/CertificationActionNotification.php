<?php

namespace App\Notifications;

use App\Models\EmployeeCertification;
use App\Models\UserNotificationPreference;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class CertificationActionNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        private readonly string $action, // 'uploaded' | 'updated' | 'deleted'
        private readonly string $title,
        private readonly string $providerName,
        private readonly string $employeeFirstName,
        private readonly ?string $dateReceived = null,
        private readonly ?string $expiryDate = null,
        private readonly bool $doesNotExpire = false,
    ) {}

    public function via($notifiable): array
    {
        return UserNotificationPreference::channelsFor($notifiable, 'certification_action', ['mail']);
    }

    public function toMail($notifiable): MailMessage
    {
        $mail = (new MailMessage)
            ->subject($this->subject())
            ->greeting("Dear {$this->employeeFirstName},");

        foreach ($this->bodyLines() as $line) {
            $mail->line($line);
        }

        if ($this->action !== 'deleted') {
            $mail->action('View My Certifications', env('FRONTEND_URL') . '/self-service/certifications');
        }

        return $mail;
    }

    public function toArray($notifiable): array
    {
        return [
            'type'          => 'certification_action',
            'action'        => $this->action,
            'title'         => $this->title,
            'provider_name' => $this->providerName,
        ];
    }

    private function subject(): string
    {
        return match ($this->action) {
            'uploaded' => "New Certification Added: {$this->title}",
            'updated'  => "Certification Updated: {$this->title}",
            'deleted'  => "Certification Removed: {$this->title}",
            default    => "Certification Notice: {$this->title}",
        };
    }

    private function bodyLines(): array
    {
        return match ($this->action) {
            'uploaded' => [
                "A new certification has been added to your profile:",
                "**Certification:** {$this->title}",
                "**Issued By:** {$this->providerName}",
                "**Date Received:** {$this->dateReceived}",
                $this->doesNotExpire
                    ? "**Expiry:** Does not expire"
                    : "**Expiry Date:** {$this->expiryDate}",
                "If you believe this was added in error, please contact HR.",
            ],
            'updated' => [
                "Your certification details have been updated by HR:",
                "**Certification:** {$this->title}",
                "**Issued By:** {$this->providerName}",
                "**Date Received:** {$this->dateReceived}",
                $this->doesNotExpire
                    ? "**Expiry:** Does not expire"
                    : "**Expiry Date:** {$this->expiryDate}",
                "If you have any questions about this change, please contact HR.",
            ],
            'deleted' => [
                "The following certification has been removed from your profile by HR:",
                "**Certification:** {$this->title}",
                "**Issued By:** {$this->providerName}",
                "If you believe this was removed in error, please contact HR immediately.",
            ],
            default => [],
        };
    }
}
