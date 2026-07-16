<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

class Video extends Model
{
    protected $fillable = [
        'channel_id', 'youtube_id', 'title', 'description', 'published_at', 'duration',
        'file_path', 'file_size', 'thumbnail_path', 'status', 'progress_percent', 'retries',
        'last_error', 'prevent_download', 'unavailable_reason', 'downloaded_at',
    ];

    protected static function booted()
    {
        static::creating(function (Video $video) {
            // Our episode numbering only has day granularity, so videos from the same channel
            // uploaded on the same calendar date would otherwise collide into the same Plex
            // episode slot. Counting down from 99 mirrors a channel listing being discovered
            // newest-first, so already-assigned indexes never need to change retroactively.
            $date = Carbon::parse($video->published_at)->toDateString();

            $minIndex = static::where('channel_id', $video->channel_id)
                ->whereRaw('date(published_at) = ?', [$date])
                ->min('upload_date_index');

            $video->upload_date_index = $minIndex === null ? 99 : $minIndex - 1;
        });
    }

    public function channel(): BelongsTo
    {
        return $this->belongsTo(Channel::class);
    }

    /**
     * Inspect yt-dlp error output for signs the video is permanently unavailable (private,
     * removed, etc.) rather than a transient failure worth retrying. Returns a short
     * human-readable reason, or null if the output doesn't match a known pattern.
     *
     * Deliberately does NOT cover members-only restrictions — see isMembersOnlyRestricted()
     * below, which is checked separately and handled quite differently (not a permanent
     * exclusion, since membership access can change).
     */
    public static function detectUnavailableReason(string $errorOutput): ?string
    {
        $errorOutputLower = strtolower($errorOutput);

        $isUnavailable = Str::contains($errorOutputLower, [
            'private video',
            'this video has been removed',
            'video unavailable',
            'this video is no longer available',
            'this video is unavailable',
        ]);

        if (! $isUnavailable) {
            return null;
        }

        if (Str::contains($errorOutputLower, 'private video')) {
            return 'Private video';
        }

        if (Str::contains($errorOutputLower, 'removed')) {
            return 'Video removed';
        }

        return 'Video is private, removed or unavailable';
    }

    /**
     * Whether yt-dlp's error output indicates the video is restricted to the channel's
     * paying members (e.g. "Join this channel to get access to members-only content", or a
     * specific membership tier via "available to this channel's members on level: ...").
     *
     * Unlike detectUnavailableReason()'s permanent reasons (private/removed), membership
     * access isn't necessarily permanent — the channel could grant access later, or the
     * video could eventually go public — so callers treat this as "quietly skip for now and
     * let a future channel check reconsider it" rather than a permanent exclusion or
     * something worth warning about.
     */
    public static function isMembersOnlyRestricted(string $errorOutput): bool
    {
        $errorOutputLower = strtolower($errorOutput);

        return Str::contains($errorOutputLower, [
            'members-only',
            "available to this channel's members",
        ]);
    }

    /**
     * Whether yt-dlp's error output indicates the video is a scheduled premiere that hasn't
     * gone live yet (e.g. "Premieres in 43 minutes"). yt-dlp can't extract downloadable
     * formats until the premiere actually starts, so this surfaces as a metadata-fetch
     * failure even though the video itself is perfectly fine.
     *
     * Like isMembersOnlyRestricted(), this is self-resolving rather than permanent — the
     * premiere will have started by a future channel check — so callers should skip it
     * quietly instead of warning about it.
     */
    public static function isUpcomingPremiere(string $errorOutput): bool
    {
        return Str::contains(strtolower($errorOutput), 'premieres in');
    }

    /**
     * URL for the locally-saved thumbnail, or null if it isn't set or the file is missing on disk.
     */
    public function thumbnailUrl(): ?string
    {
        if (! $this->thumbnail_path) {
            return null;
        }

        if (! file_exists(Setting::getStoragePath().'/'.$this->thumbnail_path)) {
            return null;
        }

        return route('media.show', ['path' => $this->thumbnail_path]);
    }

    /**
     * URL for the locally-saved video file, or null if it isn't set or the file is missing on disk.
     */
    public function videoUrl(): ?string
    {
        if (! $this->file_path) {
            return null;
        }

        if (! file_exists(Setting::getStoragePath().'/'.$this->file_path)) {
            return null;
        }

        return route('media.show', ['path' => $this->file_path]);
    }

    /**
     * Human-readable video length ("1:23:45" or "4:32"), or null if unknown (videos
     * discovered before the duration field was captured won't have one).
     */
    public function formattedDuration(): ?string
    {
        if ($this->duration === null) {
            return null;
        }

        $hours = intdiv($this->duration, 3600);
        $minutes = intdiv($this->duration % 3600, 60);
        $seconds = $this->duration % 60;

        if ($hours > 0) {
            return sprintf('%d:%02d:%02d', $hours, $minutes, $seconds);
        }

        return sprintf('%d:%02d', $minutes, $seconds);
    }

    /**
     * The video's publish time, converted from UTC storage to the app's configured display timezone.
     */
    public function publishedAtLocal(): ?Carbon
    {
        return $this->published_at
            ? Carbon::parse($this->published_at, 'UTC')->setTimezone(config('app.display_timezone'))
            : null;
    }

    /**
     * The time this video finished downloading, converted from UTC storage to the app's configured display timezone.
     */
    public function downloadedAtLocal(): ?Carbon
    {
        return $this->downloaded_at
            ? Carbon::parse($this->downloaded_at, 'UTC')->setTimezone(config('app.display_timezone'))
            : null;
    }

    /**
     * Size in bytes of the locally-saved video file, or null if it isn't set or the file is missing on disk.
     *
     * Prefers the cached `file_size` column (populated at download time) to avoid a filesystem
     * stat on every call; falls back to a live stat for videos downloaded before that column
     * existed, so they keep working correctly without needing a backfill.
     */
    public function fileSize(): ?int
    {
        if ($this->file_size !== null) {
            return $this->file_size;
        }

        if (! $this->file_path) {
            return null;
        }

        $fullPath = Setting::getStoragePath().'/'.$this->file_path;

        if (! file_exists($fullPath)) {
            return null;
        }

        return filesize($fullPath) ?: null;
    }

    /**
     * Full-text search over title/description via the videos_fts SQLite FTS5 virtual table.
     * Tokens are stripped down to safe word characters and prefix-matched, OR'd together,
     * so multi-word queries behave like a normal search box rather than a boolean expression.
     */
    public function scopeSearch(Builder $query, ?string $term): Builder
    {
        $term = trim((string) $term);

        if ($term === '') {
            return $query;
        }

        $tokens = preg_split('/\s+/u', $term, -1, PREG_SPLIT_NO_EMPTY);
        $safeTokens = [];

        foreach ($tokens as $token) {
            $clean = preg_replace('/[^\p{L}\p{N}]+/u', '', $token);
            if ($clean !== '') {
                // Quoting forces a literal phrase match, so words like "or"/"and"/"not"
                // can't be misread as FTS5 boolean operators; "*" still prefix-matches.
                $safeTokens[] = '"'.$clean.'"*';
            }
        }

        if (empty($safeTokens)) {
            return $query;
        }

        $matchQuery = implode(' OR ', $safeTokens);

        return $query
            ->join('videos_fts', 'videos_fts.rowid', '=', 'videos.id')
            ->whereRaw('videos_fts MATCH ?', [$matchQuery])
            ->select('videos.*');
    }
}
