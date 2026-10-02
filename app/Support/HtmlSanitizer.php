<?php

namespace App\Support;

use HTMLPurifier;
use HTMLPurifier_Config;

/**
 * Article body sanitizer (Django `sanitize_article_html` equivalent, built on
 * HTMLPurifier). Allow-list only: p, br, strong, b, em, i, u, h2, h3, h4, ul, ol,
 * li, blockquote, a[href|title|rel|target], img[src|alt|title]; URI schemes
 * http, https and mailto. script/style/iframe/object/embed are dropped together
 * with their content; event handlers, javascript:/data: URIs, inline styles and
 * every unknown tag/attribute are removed.
 */
class HtmlSanitizer
{
    public const ALLOWED_TAGS = ['p', 'br', 'strong', 'b', 'em', 'i', 'u', 'h2', 'h3', 'h4', 'ul', 'ol', 'li', 'blockquote', 'a', 'img'];

    private static ?HTMLPurifier $purifier = null;

    public static function clean(?string $html): string
    {
        $html = (string) $html;
        // Remove dangerous containers WITH their contents first (bleach keeps inner text).
        $html = preg_replace('#<(script|style|iframe|object|embed|noscript|template|svg|math)\b[^>]*>.*?</\1\s*>#is', '', $html) ?? '';
        // Unterminated opening tags of the same kind: drop the tag itself (purifier removes the rest).
        $html = preg_replace('#<(script|style|iframe|object|embed|noscript|template|svg|math)\b[^>]*>#i', '', $html) ?? '';

        return trim(self::purifier()->purify($html));
    }

    private static function purifier(): HTMLPurifier
    {
        if (self::$purifier) {
            return self::$purifier;
        }
        $config = HTMLPurifier_Config::createDefault();
        $cache = storage_path('framework/cache/htmlpurifier');
        if (! is_dir($cache)) {
            @mkdir($cache, 0775, true);
        }
        if (is_dir($cache) && is_writable($cache)) {
            $config->set('Cache.SerializerPath', $cache);
        } else {
            $config->set('Cache.DefinitionImpl', null);
        }
        $config->set('Core.Encoding', 'UTF-8');
        $config->set('HTML.Doctype', 'HTML 4.01 Transitional');
        $config->set('HTML.Allowed', 'p,br,strong,b,em,i,u,h2,h3,h4,ul,ol,li,blockquote,a[href|title|rel|target],img[src|alt|title]');
        $config->set('URI.AllowedSchemes', ['http' => true, 'https' => true, 'mailto' => true]);
        $config->set('Attr.AllowedFrameTargets', ['_blank']);
        $config->set('Attr.AllowedRel', ['noopener', 'noreferrer', 'nofollow']);
        $config->set('HTML.TargetNoopener', true);
        $config->set('AutoFormat.RemoveEmpty', false);
        $config->set('Attr.EnableID', false);

        return self::$purifier = new HTMLPurifier($config);
    }
}
