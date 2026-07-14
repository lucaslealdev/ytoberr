<?php

namespace Tests\Feature;

use App\Models\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SettingTest extends TestCase
{
    use RefreshDatabase;

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
