<?php

namespace GlpiPlugin\Backupinfo;

final class Text
{
    /**
     * Vinchin descriptions are HTML (`<span class="font-green">`). Plain text for storage, tickets and
     * CSV: tags removed, entities decoded, whitespace collapsed, cut to $max characters.
     */
    public static function plain(string $html, int $max): string
    {
        $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = trim((string) preg_replace('/\s+/u', ' ', $text));
        return mb_substr($text, 0, $max);
    }
}
