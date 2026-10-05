<?php

namespace App\Notifications;

use App\Models\Competency\EmployeeAuthorization;
use App\Models\UserNotificationPreference;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** Something happened to an authorization: recommended, granted, declined, suspended, revoked, expiring or expired. */
class AuthorizationNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        private readonly EmployeeAuthorization $authorization,
        private readonly string $event,
    ) {}

    public function via($notifiable): array
    {
        return UserNotificationPreference::channelsFor($notifiable, 'competency_authorization', ['mail']);
    }

    public function toMail($notifiable): MailMessage
    {
        $a = $this->authorization;
        $who = $a->employee?->name;
        $what = $a->activity?->name;

        $line = match ($this->event) {
            'recommended' => "**{$who}** has been recommended for authorization to **{$what}**. Please review and grant or decline it.",
            'granted'     => "**{$who}** is now authorized to **{$what}**" . ($a->valid_until ? ' until ' . $a->valid_until->format('F j, Y') : '') . '.',
            'declined'    => "The recommendation of **{$who}** for **{$what}** was declined.",
            'suspended'   => "**{$who}**'s authorization to **{$what}** has been suspended.",
            'revoked'     => "**{$who}**'s authorization to **{$what}** has been revoked.",
            'expiring'    => "**{$who}**'s authorization to **{$what}** expires on " . $a->valid_until?->format('F j, Y') . '.',
            'expired'     => "**{$who}**'s authorization to **{$what}** has expired.",
            default       => "**{$who}**'s authorization to **{$what}** has changed.",
        };

        $mail = (new MailMessage)->subject("Authorization: {$what} ({$who})")->greeting("Dear {$notifiable->name},")->line($line);
        if ($a->reason && in_array($this->event, ['declined', 'suspended', 'revoked'], true)) {
            $mail->line("**Reason:** {$a->reason}");
        }

        return $mail->action('Open authorizations', env('FRONTEND_URL') . '/employee-management/competency?tab=authorizations');
    }

    public function toArray($notifiable): array
    {
        return [
            'type'               => 'competency_authorization',
            'event'              => $this->event,
            'authorization_uuid' => $this->authorization->uuid,
            'employee_name'      => $this->authorization->employee?->name,
            'activity_name'      => $this->authorization->activity?->name,
            'valid_until'        => $this->authorization->valid_until?->format('Y-m-d'),
        ];
    }
}
