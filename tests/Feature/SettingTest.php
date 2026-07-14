<?php

namespace Tests\Feature;

use App\Models\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SettingTest extends TestCase
{
    use RefreshDatabase;

    public function test_disk_usage_color_class_thresholds()
    {
        $this->assertEquals('bg-green-500', Setting::diskUsageColorClass(0));
        $this->assertEquals('bg-green-500', Setting::diskUsageColorClass(70));
        $this->assertEquals('bg-orange-500', Setting::diskUsageColorClass(70.1));
        $this->assertEquals('bg-orange-500', Setting::diskUsageColorClass(90));
        $this->assertEquals('bg-red-500', Setting::diskUsageColorClass(90.1));
        $this->assertEquals('bg-red-500', Setting::diskUsageColorClass(100));
    }

    public function test_disk_usage_percent_returns_a_value_between_0_and_100_for_a_real_path()
    {
        $percent = Setting::diskUsagePercent(storage_path());

        $this->assertGreaterThanOrEqual(0, $percent);
        $this->assertLessThanOrEqual(100, $percent);
    }

    public function test_disk_usage_percent_falls_back_to_storage_path_for_a_nonexistent_directory()
    {
        $percent = Setting::diskUsagePercent('/this/path/does/not/exist/at/all');

        $this->assertGreaterThanOrEqual(0, $percent);
        $this->assertLessThanOrEqual(100, $percent);
    }

    public function test_ytdlp_delay_seconds_defaults_to_a_safe_value_when_unset()
    {
        $this->assertEquals(5, Setting::ytdlpDelaySeconds());
    }

    public function test_ytdlp_delay_seconds_returns_the_configured_value()
    {
        Setting::set('ytdlp_delay_seconds', '12');

        $this->assertEquals(12, Setting::ytdlpDelaySeconds());
    }
}
