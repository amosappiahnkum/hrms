<?php

namespace App\Listeners;

use App\Events\ApprovalRequested;
use App\Mail\ApprovalRequestedMail;
use App\Models\User;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Mail;

class SendApprovalRequestNotification implements ShouldQueue
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
    public function handle(ApprovalRequested $event): void
    {
        $approval = $event->approval;

        $approvers = User::permission('approve-employee-update')->get();

        // Nobody to notify; sending would throw and fail the user's request.
        if ($approvers->isEmpty()) {
            return;
        }

        Mail::to($approvers)
            ->queue(
                new ApprovalRequestedMail($approval)
            );
    }
}
