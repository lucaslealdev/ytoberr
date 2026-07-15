<?php

namespace Tests\Feature;

use App\Models\Channel;
use App\Models\User;
use App\Models\Video;
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
}
