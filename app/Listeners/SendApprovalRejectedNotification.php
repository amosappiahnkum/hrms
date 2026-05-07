<?php

namespace App\Listeners;

use App\Events\ApprovalRejected;
use App\Mail\ApprovalRejectedMail;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Mail;

class SendApprovalRejectedNotification implements ShouldQueue
{
    /**
     * Create the event listener.
     */
    public function __construct()
    {
        //
    }

    /**
     * Handle the event.
     */
    public function handle(ApprovalRejected $event): void
    {
        $approval = $event->approval;

        Mail::to($approval->requestedBy->email)
            ->queue(
                new ApprovalRejectedMail($approval)
            );
    }
}
