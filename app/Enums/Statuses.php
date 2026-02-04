<?php

namespace App\Enums;

enum Statuses: string
{
    case PENDING = 'pending';
    case APPROVED = 'approved';
    case HR_APPROVED = 'hr_approved';
    case HR_REJECTED = 'hr_rejected';
    case HOD_APPROVED = 'hod_approved';
    case HOD_REJECTED = 'hod_rejected';
    case CANCELED = 'canceled';

    case REJECTED = 'rejected';
    case VIEWED = 'viewed';
}
