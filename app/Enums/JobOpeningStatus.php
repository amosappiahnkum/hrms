<?php

namespace App\Enums;

enum JobOpeningStatus: string
{
    case DRAFT = 'draft';
    case OPEN = 'open';
    case CLOSED = 'closed';
}
