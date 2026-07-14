<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Warning extends Model
{
    protected $fillable = ['source', 'message', 'details', 'video_id'];

    public function video(): BelongsTo
    {
        return $this->belongsTo(Video::class);
    }

    /**
     * Record a background problem. Always inserts a new row — dismissing a warning
     * (deleting it) does not suppress future occurrences of the same problem.
     *
     * $videoId links this warning to the specific video that caused it (when applicable),
     * so the UI can offer a "retry download" action directly from the warning.
     */
    public static function log(string $source, string $message, ?string $details = null, ?int $videoId = null): self
    {
        return self::create([
            'source' => $source,
            'message' => $message,
            'details' => $details,
            'video_id' => $videoId,
        ]);
    }
}
