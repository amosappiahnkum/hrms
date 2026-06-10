<?php

namespace App\Providers;

use App\Events\ApprovalApproved;
use App\Events\ApprovalRejected;
use App\Events\ApprovalRequested;
use App\Listeners\SendApprovalApprovedNotification;
use App\Listeners\SendApprovalRejectedNotification;
use App\Listeners\SendApprovalRequestNotification;
use App\Listeners\TrackFailedLogin;
use App\Listeners\TrackSuccessfulLogin;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Registered;
use Illuminate\Auth\Listeners\SendEmailVerificationNotification;
use Illuminate\Foundation\Support\Providers\EventServiceProvider as ServiceProvider;
use Illuminate\Support\Facades\Event;

class EventServiceProvider extends ServiceProvider
{
    /**
     * The event listener mappings for the application.
     *
     * @var array
     */
    protected $listen = [
        Registered::class => [
            SendEmailVerificationNotification::class,
        ],
        ApprovalRequested::class => [
            SendApprovalRequestNotification::class,
        ],

        ApprovalApproved::class => [
            SendApprovalApprovedNotification::class,
        ],

        ApprovalRejected::class => [
            SendApprovalRejectedNotification::class,
        ],

        Login::class => [
            TrackSuccessfulLogin::class,
        ],

        Failed::class => [
            TrackFailedLogin::class,
        ],
    ];

    /**
     * Register any events for your application.
     *
     * @return void
     */
    public function boot()
    {
        //
    }
}
