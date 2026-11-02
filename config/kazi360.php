<?php

return [
    'organization' => env('ORGANIZATION'),
    'system_user' => env('SYSTEM_USER_MAIL'),
    // Secret for the fingerprints of payment numbers (to find two employees paid into the same account; the numbers
    // themselves are encrypted with APP_KEY). Keep it apart from APP_KEY and back it up; after changing it, run
    // `php artisan payroll:account-fingerprints`. Empty: APP_KEY is used.
    'payment_fingerprint_key' => env('PAYMENT_FINGERPRINT_KEY'),
];
