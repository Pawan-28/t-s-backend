<?php

namespace App\Enums;

/**
 * HOME_TOP stays a valid backend value for compatibility with existing rows
 * and the API contract. The frontend intentionally hides it; do not change that.
 */
enum AdPlacement: string
{
    case HOME_TOP = 'HOME_TOP';
    case HOME_MIDDLE = 'HOME_MIDDLE';
    case HOME_SIDEBAR = 'HOME_SIDEBAR';
    case HOME_BOTTOM = 'HOME_BOTTOM';
    case ARTICLE_TOP = 'ARTICLE_TOP';
    case ARTICLE_MIDDLE = 'ARTICLE_MIDDLE';
    case ARTICLE_BOTTOM = 'ARTICLE_BOTTOM';
}
