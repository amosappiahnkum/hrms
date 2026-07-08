<?php

namespace App\Mail\Training;

use App\Models\Training\Course;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class CourseEnrolledMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly User    $recipient,
        public readonly Course  $course,
        public readonly ?string $dueDate = null,
    ) {
        $this->afterCommit();
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: "New Training Course: {$this->course->title}",
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'emails.training.enrolled',
            with: [
                'recipientName' => $this->recipient->name,
                'courseTitle'   => $this->course->title,
                'description'   => $this->course->description,
                'dueDate'       => $this->dueDate,
                'loginUrl'      => rtrim(config('app.frontend_url'), '/') . '/training/my-courses',
            ],
        );
    }

    public function attachments(): array
    {
        return [];
    }
}
