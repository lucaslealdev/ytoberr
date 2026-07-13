<?php

namespace App\Support;

use App\Models\Channel;
use App\Models\Video;
use Carbon\Carbon;

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

    /**
     * Season (upload year) and episode number (month+day plus the video's upload_date_index,
     * e.g. "071299") for a video, matching the s{year}e{episode} pattern embedded in its filename.
     *
     * @return array{0: int, 1: string}
     */
    public static function seasonAndEpisode(Video $video): array
    {
        $publishedAt = Carbon::parse($video->published_at);
        $episode = $publishedAt->format('md').str_pad((string) $video->upload_date_index, 2, '0', STR_PAD_LEFT);

        return [$publishedAt->year, $episode];
    }

    /**
     * Base filename (no directory, no extension) for a video's file, thumbnail and .nfo,
     * following the "{channel} - s{year}e{episode} - {title} [{id}]" convention.
     */
    public static function filenameFor(Channel $channel, Video $video): string
    {
        [$year, $episode] = self::seasonAndEpisode($video);

        $safeChannelName = self::sanitize($channel->name);
        $safeTitle = self::sanitize($video->title);

        return "{$safeChannelName} - s{$year}e{$episode} - {$safeTitle} [{$video->youtube_id}]";
    }
}
