<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Channel extends Model
{
    protected $fillable = ['youtube_id', 'name', 'url', 'profile_image_path', 'download_quality', 'cutoff_date', 'description', 'download_shorts'];

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
     */
    public function totalDownloadedBytes(): int
    {
        return $this->videos->sum(fn (Video $video) => $video->fileSize() ?? 0);
    }
}
