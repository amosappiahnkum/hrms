<?php

namespace App\Enums;

enum ApplicationStatus: string
{
    case APPLIED = 'applied';
    case SCREENING = 'screening';
    case SHORTLISTED = 'shortlisted';
    case INTERVIEW = 'interview';
    case OFFERED = 'offered';
    case HIRED = 'hired';
    case REJECTED = 'rejected';
    case WITHDRAWN = 'withdrawn';
}
