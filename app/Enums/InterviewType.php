<?php

namespace App\Enums;

enum InterviewType: string
{
    case ONLINE = 'online';
    case IN_PERSON = 'in_person';
    case PHONE = 'phone';
}
