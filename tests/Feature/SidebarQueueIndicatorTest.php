<?php

namespace Tests\Feature;

use App\Models\Channel;
use App\Models\Setting;
use App\Models\User;
use App\Models\Video;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SidebarQueueIndicatorTest extends TestCase
{
    use RefreshDatabase;

    public function test_sidebar_shows_pending_queue_badge_next_to_processes_when_advanced_mode_is_enabled()
    {
        Setting::set('advanced_mode', '1');

        $user = User::factory()->create();
        $channel = Channel::create([
            'youtube_id' => 'UC_queue_chan',
            'name' => 'Queue Channel',
            'url' => 'https://example.com/queue',
        ]);

        Video::create([
            'channel_id' => $channel->id,
            'youtube_id' => 'queue_vid_pending',
            'title' => 'Pending Video',
            'published_at' => now(),
            'status' => 'pending',
        ]);

        Video::create([
            'channel_id' => $channel->id,
            'youtube_id' => 'queue_vid_downloading',
            'title' => 'Downloading Video',
            'published_at' => now(),
            'status' => 'downloading',
        ]);

        Video::create([
            'channel_id' => $channel->id,
            'youtube_id' => 'queue_vid_completed',
            'title' => 'Completed Video',
            'published_at' => now(),
            'status' => 'completed',
        ]);

        // Badge should appear on an arbitrary page, not just Processes itself.
        $response = $this->actingAs($user)->get('/channels');

        $response->assertStatus(200);
        $response->assertSee('🧩 Processes', false);

        // Only the pending + downloading videos (2) should be counted, not the completed one.
        $content = $response->getContent();
        $this->assertMatchesRegularExpression(
            '/bg-blue-600 text-white text-xs rounded-full px-2[^"]*">\s*2\s*<\/span>/',
            $content
        );
    }

    public function test_sidebar_does_not_show_queue_badge_when_advanced_mode_is_disabled()
    {
        $user = User::factory()->create();
        $channel = Channel::create([
            'youtube_id' => 'UC_queue_no_advanced_chan',
            'name' => 'Queue No Advanced Channel',
            'url' => 'https://example.com/queuenoadvanced',
        ]);

        Video::create([
            'channel_id' => $channel->id,
            'youtube_id' => 'queue_no_advanced_vid',
            'title' => 'Pending Video',
            'published_at' => now(),
            'status' => 'pending',
        ]);

        // Advanced Mode is off by default, so the Processes link (and its queue badge) shouldn't
        // render at all, even though there's a pending video.
        $response = $this->actingAs($user)->get('/channels');

        $response->assertStatus(200);
        $response->assertDontSee('🧩 Processes', false);
        $response->assertDontSee('bg-blue-600 text-white text-xs rounded-full', false);
    }

    public function test_sidebar_hides_queue_badge_when_no_videos_are_pending_or_downloading()
    {
        Setting::set('advanced_mode', '1');

        $user = User::factory()->create();
        $channel = Channel::create([
            'youtube_id' => 'UC_no_queue_chan',
            'name' => 'No Queue Channel',
            'url' => 'https://example.com/no-queue',
        ]);

        Video::create([
            'channel_id' => $channel->id,
            'youtube_id' => 'no_queue_vid_completed',
            'title' => 'Completed Video',
            'published_at' => now(),
            'status' => 'completed',
        ]);

        Video::create([
            'channel_id' => $channel->id,
            'youtube_id' => 'no_queue_vid_failed',
            'title' => 'Failed Video',
            'published_at' => now(),
            'status' => 'failed',
        ]);

        $response = $this->actingAs($user)->get('/');

        $response->assertStatus(200);
        $response->assertDontSee('bg-blue-600 text-white text-xs rounded-full', false);
    }
}
