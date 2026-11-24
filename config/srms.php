<?php

/*
 * SRMS (TESS) receives staff details from HRMS (HR owns them): /api/internal/v1/staff/bio-data on SRMS, with the
 * key SRMS gave HRMS (its INTERNAL_API_KEY_HRMS). TTU only: also needs the integrations.srms feature switched on
 * (off by default, so other organisations never send anything).
 */
return [
    'url' => env('SRMS_API_URL'), // e.g. https://srms-api.ttu.edu.gh/api/internal/v1
    'key' => env('SRMS_API_KEY'),
];
