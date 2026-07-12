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
}
