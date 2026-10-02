<?php

namespace App\Enums;

enum ReviewAction: string
{
    case SUBMITTED = 'SUBMITTED';
    case RESUBMITTED = 'RESUBMITTED';
    case ASSIGNED = 'ASSIGNED'; // reporter assigned while the article stays DRAFT (no status change)
    case STARTED_REVIEW = 'STARTED_REVIEW';
    case CHANGES_REQUESTED = 'CHANGES_REQUESTED';
    case REJECTED = 'REJECTED';
    case APPROVED = 'APPROVED';
    case SCHEDULED = 'SCHEDULED';
    case RESCHEDULED = 'RESCHEDULED';
    case SCHEDULE_CANCELLED = 'SCHEDULE_CANCELLED';
    case PUBLISHED = 'PUBLISHED';
}
