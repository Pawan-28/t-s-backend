<?php

namespace App\Enums;

enum Role: string
{
    case ADMIN = 'ADMIN';
    case REPORTER = 'REPORTER';
    case USER = 'USER';
    case SUBSCRIBER = 'SUBSCRIBER';
}
