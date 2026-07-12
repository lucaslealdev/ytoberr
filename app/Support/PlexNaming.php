<?php

namespace App\Support;

class PlexNaming
{
    /**
     * Sanitize a title/name for use in a filename while preserving readability
     * (case, spaces, accents) for Plex, only stripping filesystem-unsafe characters.
     */
    public static function sanitize(string $value): string
    {
        $value = str_replace(['/', '\\', ':', '*', '?', '"', '<', '>', '|'], ' ', $value);
        $value = trim(preg_replace('/\s+/', ' ', $value));

        return $value;
    }
}
