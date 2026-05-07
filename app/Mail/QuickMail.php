<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class QuickMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    /**
     * @param string $subjectText
     * @param string $bodyText
     * @param array $attachmentPaths
     */
    public function __construct(
        public string $subjectText,
        public string $bodyText,
        public array $attachmentPaths = []
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: $this->subjectText,
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.dynamic',
            with: ['body' => $this->bodyText],
        );
    }

    public function attachments(): array
    {
        $attachments = [];

        foreach ($this->attachmentPaths as $path) {
            // Double-check: does the file actually exist on disk?
            if ($path && file_exists($path)) {
                $attachments[] = Attachment::fromPath($path);
            } else {
                // Optional: Log this error so you know an attachment was missing
                \Log::warning("Attachment not found at path: " . ($path ?? 'null'));
            }
        }

        return $attachments;
    }
}
