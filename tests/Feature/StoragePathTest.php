<?php

namespace Tests\Feature;

use App\Models\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StoragePathTest extends TestCase
{
    use RefreshDatabase;

    public function test_storage_path_priority_defaults_to_storage_folder()
    {
        // Ensure no setting in DB and no ENV for test
        Setting::where('key', 'storage_path')->delete();
        putenv('DOWNLOADS_PATH');
        putenv('STORAGE_PATH');

        $expected = storage_path('app/public/downloads');
        $this->assertEquals($expected, Setting::getStoragePath());
    }

    public function test_storage_path_priority_prefers_env()
    {
        Setting::where('key', 'storage_path')->delete();
        putenv('DOWNLOADS_PATH=/custom/env/downloads');

        $this->assertEquals('/custom/env/downloads', Setting::getStoragePath());

        // Cleanup env
        putenv('DOWNLOADS_PATH');
    }

    public function test_storage_path_priority_prefers_db()
    {
        putenv('DOWNLOADS_PATH=/custom/env/downloads');
        Setting::set('storage_path', '/custom/db/downloads');

        $this->assertEquals('/custom/db/downloads', Setting::getStoragePath());

        // Cleanup env and setting
        putenv('DOWNLOADS_PATH');
        Setting::where('key', 'storage_path')->delete();
    }
}
