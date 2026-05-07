<?php

namespace App\Listeners;

use App\Events\ApprovalApproved;
use App\Mail\ApprovalApprovedMail;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Mail;

class SendApprovalApprovedNotification implements ShouldQueue
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
    public function handle(ApprovalApproved $event): void
    {
        $approval = $event->approval;

        Mail::to($approval->requestedBy->email)->queue(new ApprovalApprovedMail($approval));
    }
}
