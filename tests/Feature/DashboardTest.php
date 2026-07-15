<?php

namespace Tests\Feature;

use App\Models\Channel;
use App\Models\Setting;
use App\Models\User;
use App\Models\Video;
use App\Models\Warning;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_dashboard_shows_recent_completed_videos()
    {
        $user = User::factory()->create();
        $channel = Channel::create([
            'youtube_id' => 'UC_dashboard_chan',
            'name' => 'Dashboard Channel',
            'url' => 'https://example.com/dashboard',
        ]);

        $older = Video::create([
            'channel_id' => $channel->id,
            'youtube_id' => 'older_vid',
            'title' => 'Older Video',
            'published_at' => now()->subDays(2),
            'status' => 'completed',
            'downloaded_at' => now()->subDay(),
        ]);

        $newer = Video::create([
            'channel_id' => $channel->id,
            'youtube_id' => 'newer_vid',
            'title' => 'Newer Video',
            'published_at' => now()->subDay(),
            'status' => 'completed',
            'downloaded_at' => now(),
        ]);

        // Still pending: must not show up as a "recent video".
        Video::create([
            'channel_id' => $channel->id,
            'youtube_id' => 'pending_vid',
            'title' => 'Pending Video',
            'published_at' => now(),
            'status' => 'pending',
        ]);

        $response = $this->actingAs($user)->get('/');

        $response->assertStatus(200);
        $response->assertSee('Newer Video');
        $response->assertSee('Older Video');
        $response->assertDontSee('Pending Video');
        $response->assertSee('Dashboard Channel');

        // Most recently downloaded video should be listed first.
        $content = $response->getContent();
        $this->assertTrue(strpos($content, 'Newer Video') < strpos($content, 'Older Video'));
    }

    public function test_archived_videos_count_only_includes_completed_videos()
    {
        // Regression test: 'excluded' rows (Shorts/before-cutoff/live-originated candidates
        // persisted purely so they aren't re-checked forever) and queue rows (pending/failed)
        // must not inflate the "Archived Videos" stat — it should reflect what's actually
        // been downloaded, matching the Recent Videos list below it.
        $user = User::factory()->create();
        $channel = Channel::create([
            'youtube_id' => 'UC_archived_count_chan',
            'name' => 'Archived Count Channel',
            'url' => 'https://example.com/archivedcount',
        ]);

        Video::create([
            'channel_id' => $channel->id,
            'youtube_id' => 'completed_vid',
            'title' => 'Completed Video',
            'published_at' => now(),
            'status' => 'completed',
            'downloaded_at' => now(),
        ]);

        Video::create([
            'channel_id' => $channel->id,
            'youtube_id' => 'pending_vid',
            'title' => 'Pending Video',
            'published_at' => now(),
            'status' => 'pending',
        ]);

        Video::create([
            'channel_id' => $channel->id,
            'youtube_id' => 'excluded_vid',
            'title' => 'Excluded Short',
            'published_at' => now(),
            'status' => 'excluded',
            'prevent_download' => true,
            'unavailable_reason' => 'YouTube Short (not enabled for this channel)',
        ]);

        $response = $this->actingAs($user)->get('/');

        $response->assertStatus(200);
        $response->assertViewHas('videosCount', 1);
        $this->assertSame(3, Video::count());
    }

    public function test_dashboard_footer_shows_the_application_version()
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get('/');

        $response->assertStatus(200);
        $response->assertSee('Ytoberr v'.config('app.version'));
    }

    public function test_dashboard_shows_empty_state_when_no_videos_downloaded()
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get('/');

        $response->assertStatus(200);
        $response->assertSee('No videos downloaded yet.');
    }

    public function test_dashboard_health_panel_shows_pending_failed_and_warning_counts()
    {
        $user = User::factory()->create();
        $channel = Channel::create([
            'youtube_id' => 'UC_health_chan',
            'name' => 'Health Channel',
            'url' => 'https://example.com/health',
        ]);

        Video::create(['channel_id' => $channel->id, 'youtube_id' => 'health_pending', 'title' => 'Pending', 'published_at' => now(), 'status' => 'pending']);
        Video::create(['channel_id' => $channel->id, 'youtube_id' => 'health_downloading', 'title' => 'Downloading', 'published_at' => now(), 'status' => 'downloading']);
        Video::create(['channel_id' => $channel->id, 'youtube_id' => 'health_failed_1', 'title' => 'Failed One', 'published_at' => now(), 'status' => 'failed']);
        Video::create(['channel_id' => $channel->id, 'youtube_id' => 'health_failed_2', 'title' => 'Failed Two', 'published_at' => now(), 'status' => 'failed']);

        Warning::log('test_source', 'Something went wrong');

        $response = $this->actingAs($user)->get('/');

        $response->assertStatus(200);
        $response->assertViewHas('pendingVideosCount', 2);
        $response->assertViewHas('failedVideosCount', 2);
        $response->assertViewHas('warningsCount', 1);
        $response->assertSee('System Health');
    }

    public function test_dashboard_shows_queue_suspended_banner_after_three_consecutive_failures()
    {
        $user = User::factory()->create();
        Setting::set('consecutive_failures', '3');

        $response = $this->actingAs($user)->get('/');

        $response->assertStatus(200);
        $response->assertViewHas('queueSuspended', true);
        $response->assertSee('Downloads are currently suspended');
    }

    public function test_dashboard_hides_queue_suspended_banner_when_healthy()
    {
        $user = User::factory()->create();
        Setting::set('consecutive_failures', '1');

        $response = $this->actingAs($user)->get('/');

        $response->assertStatus(200);
        $response->assertViewHas('queueSuspended', false);
        $response->assertDontSee('Downloads are currently suspended');
    }

    public function test_dashboard_storage_growth_chart_shows_empty_state_with_no_download_history()
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get('/');

        $response->assertStatus(200);
        $response->assertSee('Storage Growth');
        $response->assertSee('Not enough download history yet to chart storage growth.');
    }

    public function test_dashboard_storage_growth_chart_renders_when_downloads_exist()
    {
        $user = User::factory()->create();
        $channel = Channel::create([
            'youtube_id' => 'UC_growth_chan',
            'name' => 'Growth Channel',
            'url' => 'https://example.com/growth',
        ]);

        Video::create([
            'channel_id' => $channel->id,
            'youtube_id' => 'growth_vid',
            'title' => 'Growth Video',
            'published_at' => now(),
            'status' => 'completed',
            'downloaded_at' => now(),
            'file_size' => 5_000_000,
        ]);

        $response = $this->actingAs($user)->get('/');

        $response->assertStatus(200);
        $response->assertViewHas('storageGrowthSeries', function ($series) {
            return count($series) === 30 && end($series)['bytes'] === 5_000_000;
        });
        $response->assertDontSee('Not enough download history yet to chart storage growth.');
        $response->assertSee('<polyline', false);
    }
}
