<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Channel extends Model
{
    /**
     * Hours between automatic "new videos" checks for a channel that hasn't set its own
     * check_interval_hours — matches the scheduled sweep's historical fixed cadence.
     */
    public const DEFAULT_CHECK_INTERVAL_HOURS = 3;

    protected $fillable = [
        'youtube_id', 'name', 'url', 'profile_image_path', 'banner_path', 'fanart_path',
        'download_quality', 'cutoff_date', 'description', 'download_shorts',
        'check_interval_hours', 'last_checked_at',
    ];

    protected function casts(): array
    {
        return [
            'last_checked_at' => 'datetime',
        ];
    }

    /**
     * This channel's effective check interval: its own override if set, otherwise the
     * global default.
     */
    public function checkIntervalHours(): int
    {
        return $this->check_interval_hours ?? self::DEFAULT_CHECK_INTERVAL_HOURS;
    }

    /**
     * Whether this channel is due for another automatic "new videos" check right now, based
     * on when it was last checked and its effective check interval. A channel that has never
     * been checked is always due.
     */
    public function isDueForCheck(): bool
    {
        return ! $this->last_checked_at || $this->last_checked_at->lte(now()->subHours($this->checkIntervalHours()));
    }

    protected static function booted()
    {
        static::creating(function ($channel) {
            if (empty($channel->cutoff_date)) {
                $channel->cutoff_date = now()->toDateString();
            }
        });
    }

    public function videos(): HasMany
    {
        return $this->hasMany(Video::class);
    }

    /**
     * Total size in bytes of all locally-downloaded videos for this channel.
     *
     * Sums the cached `file_size` column directly in the database (no filesystem access) for
     * videos that have one, and only falls back to a live per-video filesystem stat for the
     * remainder — videos downloaded before that column existed and not yet backfilled.
     */
    public function totalDownloadedBytes(): int
    {
        $knownBytes = (int) $this->videos()->whereNotNull('file_size')->sum('file_size');

        $unknownBytes = $this->videos()
            ->whereNull('file_size')
            ->whereNotNull('file_path')
            ->get()
            ->sum(fn (Video $video) => $video->fileSize() ?? 0);

        return $knownBytes + $unknownBytes;
    }

    /**
     * URL for the channel's cover image (banner takes priority over fanart), or null if
     * neither was found. Backed by the stored banner_path/fanart_path columns rather than
     * a live Storage::exists() check, since both are set once by ChannelService when the
     * images are downloaded instead of being stat'd on every page render.
     */
    public function coverImageUrl(): ?string
    {
        if ($this->banner_path) {
            return asset('storage/'.$this->banner_path);
        }

        if ($this->fanart_path) {
            return asset('storage/'.$this->fanart_path);
        }

        return null;
    }
}
