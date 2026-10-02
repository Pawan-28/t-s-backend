<?php

namespace App\Support;

class PlainText
{
    /** Strip tags (tags act as word separators), decode entities, collapse whitespace. */
    public static function fromHtml(?string $value): string
    {
        $text = preg_replace('/<[^>]*>/', ' ', (string) $value) ?? '';
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return trim(preg_replace('/\s+/u', ' ', $text) ?? '');
    }

    /** Django-style plain text for short author fields: drop script/style with content, strip tags, decode, collapse. */
    public static function strip(?string $value): string
    {
        $text = preg_replace('#<(script|style)\b[^>]*>.*?</\1\s*>#is', '', (string) $value) ?? '';
        $text = html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return trim(preg_replace('/\s+/u', ' ', $text) ?? '');
    }
}
