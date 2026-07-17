<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Setting extends Model
{
    protected $fillable = ['key', 'value'];

    /**
     * Get a setting value by key.
     */
    public static function get(string $key, $default = null): ?string
    {
        $setting = self::where('key', $key)->first();

        return $setting ? $setting->value : $default;
    }

    /**
     * Set a setting value.
     */
    public static function set(string $key, ?string $value): self
    {
        return self::updateOrCreate(
            ['key' => $key],
            ['value' => $value]
        );
    }

    /**
     * Resolve the storage path for downloaded videos based on priorities:
     * 1. Database Setting ('storage_path')
     * 2. ENV ('DOWNLOADS_PATH')
     * 3. Default Storage Directory (storage_path('app/public/downloads'))
     */
    public static function getStoragePath(): string
    {
        $dbPath = self::get('storage_path');
        if ($dbPath) {
            return rtrim($dbPath, '/');
        }

        $envPath = env('DOWNLOADS_PATH') ?? env('STORAGE_PATH');
        if ($envPath) {
            return rtrim($envPath, '/');
        }

        return storage_path('app/public/downloads');
    }

    /**
     * Seconds to sleep between yt-dlp requests/downloads. Defaults to a conservative value
     * to avoid triggering YouTube's IP rate-limiting/bot-check on frequent polling.
     */
    public static function ytdlpDelaySeconds(): int
    {
        return (int) self::get('ytdlp_delay_seconds', '5');
    }

    /**
     * Whether the "Processes" page (background job/queue internals) is enabled. Off by
     * default since it exposes low-level details most users won't need day to day.
     */
    public static function advancedModeEnabled(): bool
    {
        return self::get('advanced_mode', '0') === '1';
    }

    /**
     * Whether the light theme (white background, red accent) is active. Off by default — the
     * dark theme (the app's hardcoded default styling) is what every view is authored against.
     */
    public static function lightModeEnabled(): bool
    {
        return self::get('light_mode', '0') === '1';
    }

    /**
     * Percentage of disk space used at $path (or storage_path() as a fallback, if $path
     * doesn't exist yet — e.g. a custom storage path that hasn't been created).
     */
    public static function diskUsagePercent(string $path): float
    {
        if (! is_dir($path)) {
            $path = storage_path();
        }

        $total = @disk_total_space($path) ?: 0;
        $free = @disk_free_space($path) ?: 0;

        return $total > 0 ? round((($total - $free) / $total) * 100, 1) : 0.0;
    }

    /**
     * Tailwind background color class for a disk-usage bar: green up to 70%,
     * orange above 70%, red above 90%.
     */
    public static function diskUsageColorClass(float $percent): string
    {
        if ($percent > 90) {
            return 'bg-red-500';
        }

        if ($percent > 70) {
            return 'bg-orange-500';
        }

        return 'bg-green-500';
    }
}
