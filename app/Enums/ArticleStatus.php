<?php

namespace App\Enums;

enum ArticleStatus: string
{
    case DRAFT = 'DRAFT';
    case SUBMITTED = 'SUBMITTED';
    case UNDER_REVIEW = 'UNDER_REVIEW';
    case CHANGES_REQUESTED = 'CHANGES_REQUESTED';
    case REJECTED = 'REJECTED';
    case APPROVED = 'APPROVED';
    case SCHEDULED = 'SCHEDULED';
    case PUBLISHED = 'PUBLISHED';

    /**
     * Single source of truth for allowed transitions (ported 1:1 from Django
     * articles/transitions.py). REJECTED and PUBLISHED are terminal.
     *
     * @return array<string, list<string>>
     */
    public static function transitions(): array
    {
        return [
            'DRAFT' => ['SUBMITTED', 'UNDER_REVIEW', 'CHANGES_REQUESTED', 'REJECTED', 'PUBLISHED', 'SCHEDULED'],
            'SUBMITTED' => ['UNDER_REVIEW', 'CHANGES_REQUESTED', 'REJECTED', 'APPROVED'],
            'UNDER_REVIEW' => ['UNDER_REVIEW', 'CHANGES_REQUESTED', 'REJECTED', 'APPROVED', 'PUBLISHED', 'SCHEDULED'],
            'CHANGES_REQUESTED' => ['SUBMITTED'],
            'APPROVED' => ['SCHEDULED', 'PUBLISHED'],
            'SCHEDULED' => ['SCHEDULED', 'PUBLISHED', 'APPROVED'],
            'REJECTED' => [],
            'PUBLISHED' => [],
        ];
    }

    public function canTransitionTo(self $to): bool
    {
        return in_array($to->value, self::transitions()[$this->value], true);
    }
}
