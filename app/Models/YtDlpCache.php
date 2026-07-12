<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class YtDlpCache extends Model
{
    protected $fillable = ['key', 'value', 'expires_at'];

    protected $casts = [
        'value' => 'array',
        'expires_at' => 'datetime',
    ];

    /**
     * Check if the cache entry is expired.
     */
    public function isExpired(): bool
    {
        return $this->expires_at->isPast();
    }
}
