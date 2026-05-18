<?php

namespace App\Jobs;

use App\Models\SelfService\ContactDetail;
use App\Notifications\LeaveRequestNotification;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Auth;

class SendLeaveRequestJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    private $data;

    private ContactDetail $to;
    /**
     * Create a new job instance.
     *
     * @return void
     */
    public function __construct($data, $to)
    {
        $this->data = $data;
        $this->to = $to;
    }

    /**
     * Execute the job.
     *
     * @return void
     */
    public function handle(): void
    {

//       Notification::send($this->to, new LeaveRequestNotification($this->data));
        $user = Auth::user();
        $user->notify(new LeaveRequestNotification($this->data));
    }
}
