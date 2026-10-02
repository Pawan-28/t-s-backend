<?php

namespace App\Enums;

enum AccessLevel: string
{
    case PUBLIC = 'PUBLIC';
    case SUBSCRIBER_ONLY = 'SUBSCRIBER_ONLY';
    case RESTRICTED = 'RESTRICTED';
}
