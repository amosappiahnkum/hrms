<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * A support request, for the support team: who sent it, from which organization, and the message itself. Replying
 * goes straight to the person who asked (Reply-To), with the ticket number in the subject.
 */
class SupportRequestMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $name,
        public string $email,
        public string $organization,
        public string $ticketNo,
        public string $description,
        public string $submittedAt,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            replyTo: [new Address($this->email, $this->name)],
            subject: "[{$this->ticketNo}] Support request from {$this->name} ({$this->organization})",
        );
    }

    public function content(): Content
    {
        return new Content(markdown: 'emails.support.request');
    }

    public function attachments(): array
    {
        return [];
    }
}
