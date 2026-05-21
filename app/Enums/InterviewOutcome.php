<?php

namespace App\Enums;

enum InterviewOutcome: string
{
    case PENDING = 'pending';
    case PASS = 'pass';
    case FAIL = 'fail';
    case NO_SHOW = 'no_show';
}
