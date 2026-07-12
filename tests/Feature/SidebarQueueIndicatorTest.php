<?php

namespace Tests\Feature;

use App\Models\Channel;
use App\Models\User;
use App\Models\Video;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SidebarQueueIndicatorTest extends TestCase
{
    use RefreshDatabase;

    public function test_sidebar_shows_pending_queue_badge_when_videos_are_pending_or_downloading()
    {
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

        // Badge should appear on an arbitrary page, not just Settings.
        $response = $this->actingAs($user)->get('/channels');

        $response->assertStatus(200);
        $response->assertSee('⚙️ Settings');

        // Only the pending + downloading videos (2) should be counted, not the completed one.
        $content = $response->getContent();
        $this->assertMatchesRegularExpression(
            '/bg-blue-600 text-white text-xs rounded-full px-2[^"]*">\s*2\s*<\/span>/',
            $content
        );
    }

    public function test_sidebar_hides_queue_badge_when_no_videos_are_pending_or_downloading()
    {
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
