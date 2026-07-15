<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Channel extends Model
{
    protected $fillable = ['youtube_id', 'name', 'url', 'profile_image_path', 'banner_path', 'fanart_path', 'download_quality', 'cutoff_date', 'description', 'download_shorts'];

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
