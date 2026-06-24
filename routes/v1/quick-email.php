<?php

use App\Http\Controllers\QuickEmailController;
use Illuminate\Support\Facades\Route;

Route::middleware('feature:quick_email.enabled')
    ->post('mail/send', [QuickEmailController::class, 'send']);
