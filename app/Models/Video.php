<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Video extends Model
{
    protected $fillable = [
        'channel_id', 'youtube_id', 'title', 'description', 'published_at',
        'file_path', 'thumbnail_path', 'status', 'retries', 'last_error',
        'prevent_download', 'unavailable_reason', 'downloaded_at'
    ];

    public function channel(): BelongsTo
    {
        return $this->belongsTo(Channel::class);
    }

    /**
     * URL for the locally-saved thumbnail, or null if it isn't set or the file is missing on disk.
     */
    public function thumbnailUrl(): ?string
    {
        if (!$this->thumbnail_path) {
            return null;
        }

        if (!file_exists(Setting::getStoragePath() . '/' . $this->thumbnail_path)) {
            return null;
        }

        return route('media.show', ['path' => $this->thumbnail_path]);
    }

    /**
     * URL for the locally-saved video file, or null if it isn't set or the file is missing on disk.
     */
    public function videoUrl(): ?string
    {
        if (!$this->file_path) {
            return null;
        }

        if (!file_exists(Setting::getStoragePath() . '/' . $this->file_path)) {
            return null;
        }

        return route('media.show', ['path' => $this->file_path]);
    }

    /**
     * Size in bytes of the locally-saved video file, or null if it isn't set or the file is missing on disk.
     */
    public function fileSize(): ?int
    {
        if (!$this->file_path) {
            return null;
        }

        $fullPath = Setting::getStoragePath() . '/' . $this->file_path;

        if (!file_exists($fullPath)) {
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
                $safeTokens[] = '"' . $clean . '"*';
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
