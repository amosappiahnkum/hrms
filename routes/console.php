<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

/*
|--------------------------------------------------------------------------
| Console Routes
|--------------------------------------------------------------------------
|
| This file is where you may define all of your Closure based console
| commands. Each Closure is bound to a command instance allowing a
| simple approach to interacting with each command's IO methods.
|
*/

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('leave:send-resumption-reminders')->dailyAt('08:00');
Schedule::command('certifications:send-expiry-reminders')->dailyAt('08:00');
// After certificate reminders: certificates that lapsed overnight suspend the authorizations they support.
Schedule::command('competency:check-authorizations')->dailyAt('08:15');
Schedule::command('training-plan:send-reminders')->dailyAt('08:00');
