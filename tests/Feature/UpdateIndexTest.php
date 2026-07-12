<?php

namespace Tests\Feature;

use App\Models\Channel;
use App\Models\Video;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UpdateIndexTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        
        $user = User::factory()->create();
        $this->actingAs($user);
    }

    public function test_can_check_and_find_missing_videos()
    {
        $channel = Channel::create([
            'youtube_id' => 'UC_test',
            'name' => 'Test Channel',
            'url' => 'https://example.com',
        ]);

        // Create a video record with a missing file_path
        $video = Video::create([
            'channel_id' => $channel->id,
            'youtube_id' => 'video_missing_123',
            'title' => 'Missing Video Title',
            'published_at' => now(),
            'file_path' => 'this/file/does/not/exist.mkv',
        ]);

        $response = $this->getJson('/settings/check-missing-videos');

        $response->assertStatus(200);
        $response->assertJsonFragment([
            'id' => $video->id,
            'title' => 'Missing Video Title',
            'channel' => 'Test Channel',
            'file_path' => 'this/file/does/not/exist.mkv',
        ]);
    }

    public function test_can_clean_missing_videos()
    {
        $channel = Channel::create([
            'youtube_id' => 'UC_test',
            'name' => 'Test Channel',
            'url' => 'https://example.com',
        ]);

        $video = Video::create([
            'channel_id' => $channel->id,
            'youtube_id' => 'video_missing_123',
            'title' => 'Missing Video Title',
            'published_at' => now(),
            'file_path' => 'this/file/does/not/exist.mkv',
        ]);

        $this->assertDatabaseHas('videos', ['id' => $video->id]);

        $response = $this->post('/settings/clean-missing-videos');

        $response->assertStatus(302); // Redirect back
        $this->assertDatabaseMissing('videos', ['id' => $video->id]);
    }

    public function test_clean_index_only_removes_missing_videos_and_preserves_existing_ones()
    {
        $channel = Channel::create([
            'youtube_id' => 'UC_test',
            'name' => 'Test Channel',
            'url' => 'https://example.com',
        ]);

        $downloadsDir = \App\Models\Setting::getStoragePath();
        if (!file_exists($downloadsDir)) {
            mkdir($downloadsDir, 0755, true);
        }

        // 1. Video 1: Missing (does not exist)
        $videoMissing = Video::create([
            'channel_id' => $channel->id,
            'youtube_id' => 'video_missing',
            'title' => 'Missing Video',
            'published_at' => now(),
            'file_path' => 'missing_video.mkv',
        ]);

        // 2. Video 2: Existing (create a real temp file)
        $videoExisting = Video::create([
            'channel_id' => $channel->id,
            'youtube_id' => 'video_existing',
            'title' => 'Existing Video',
            'published_at' => now(),
            'file_path' => 'existing_video.mkv',
        ]);

        $realFile = $downloadsDir . '/existing_video.mkv';
        file_put_contents($realFile, 'dummy content');

        // Confirm both exist in DB before cleaning
        $this->assertDatabaseHas('videos', ['id' => $videoMissing->id]);
        $this->assertDatabaseHas('videos', ['id' => $videoExisting->id]);

        // Call clean missing videos
        $response = $this->post('/settings/clean-missing-videos');

        $response->assertStatus(302); // Redirect back

        // Video 1 (missing) must be deleted
        $this->assertDatabaseMissing('videos', ['id' => $videoMissing->id]);

        // Video 2 (existing) must STILL exist
        $this->assertDatabaseHas('videos', ['id' => $videoExisting->id]);

        // Cleanup
        unlink($realFile);
    }
}
