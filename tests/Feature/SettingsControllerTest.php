<?php

namespace Tests\Feature;

use App\Models\Channel;
use App\Models\Setting;
use App\Models\User;
use App\Models\Video;
use App\Models\YtDlpCache;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SettingsControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        $downloadsDir = Setting::getStoragePath();
        if (file_exists($downloadsDir . '/Settings Test Channel')) {
            exec('rm -rf ' . escapeshellarg($downloadsDir . '/Settings Test Channel'));
        }

        parent::tearDown();
    }

    public function test_index_renders_with_ytdlp_version_cache_count_and_queued_videos()
    {
        $user = User::factory()->create();

        $ytDlp = base_path('bin/yt-dlp');
        $expectedVersion = trim(shell_exec(escapeshellarg($ytDlp) . ' --version'));

        YtDlpCache::create([
            'key' => 'cache-key-1',
            'value' => ['foo' => 'bar'],
            'expires_at' => now()->addHour(),
        ]);
        YtDlpCache::create([
            'key' => 'cache-key-2',
            'value' => ['foo' => 'baz'],
            'expires_at' => now()->addHour(),
        ]);

        $channel = Channel::create([
            'youtube_id' => 'UC_settings_chan',
            'name' => 'Settings Test Channel',
            'url' => 'https://example.com/settings',
        ]);

        $pendingVideo = Video::create([
            'channel_id' => $channel->id,
            'youtube_id' => 'settings_vid_pending',
            'title' => 'Queued Pending Video',
            'published_at' => now(),
            'status' => 'pending',
        ]);

        $downloadingVideo = Video::create([
            'channel_id' => $channel->id,
            'youtube_id' => 'settings_vid_downloading',
            'title' => 'Queued Downloading Video',
            'published_at' => now(),
            'status' => 'downloading',
        ]);

        // Completed videos are not part of the queue and must not show up.
        Video::create([
            'channel_id' => $channel->id,
            'youtube_id' => 'settings_vid_completed',
            'title' => 'Completed Video Not In Queue',
            'published_at' => now(),
            'status' => 'completed',
        ]);

        $response = $this->actingAs($user)->get('/settings');

        $response->assertStatus(200);
        $response->assertSee($expectedVersion);
        $response->assertSee($pendingVideo->title);
        $response->assertSee($downloadingVideo->title);
        $response->assertDontSee('Completed Video Not In Queue');

        $this->assertMatchesRegularExpression(
            '/Total cached yt-dlp metadata queries:.*?<span[^>]*>2<\/span>/s',
            $response->getContent()
        );
    }

    public function test_update_storage_path_persists_the_setting()
    {
        $user = User::factory()->create();

        $newPath = '/tmp/ytoberr-test-storage-path';

        $response = $this->actingAs($user)->post('/settings/storage-path', [
            'storage_path' => $newPath,
        ]);

        $response->assertRedirect();
        $this->assertEquals($newPath, Setting::getStoragePath());
    }

    public function test_reset_cache_empties_the_yt_dlp_caches_table()
    {
        $user = User::factory()->create();

        YtDlpCache::create([
            'key' => 'cache-key-to-clear',
            'value' => ['foo' => 'bar'],
            'expires_at' => now()->addHour(),
        ]);

        $this->assertSame(1, YtDlpCache::count());

        $response = $this->actingAs($user)->post('/settings/reset-cache');

        $response->assertRedirect();
        $this->assertSame(0, YtDlpCache::count());
    }

    public function test_check_missing_videos_reports_only_videos_whose_file_is_absent_on_disk()
    {
        $user = User::factory()->create();

        $channel = Channel::create([
            'youtube_id' => 'UC_settings_missing_chan',
            'name' => 'Settings Test Channel',
            'url' => 'https://example.com/settings-missing',
        ]);

        $missingVideo = Video::create([
            'channel_id' => $channel->id,
            'youtube_id' => 'settings_vid_missing',
            'title' => 'Video With Missing File',
            'published_at' => now(),
            'status' => 'completed',
            'file_path' => 'Settings Test Channel/Season 2026/missing-video.mp4',
        ]);

        $downloadsDir = Setting::getStoragePath();
        $existingRelativePath = 'Settings Test Channel/Season 2026/existing-video.mp4';
        @mkdir($downloadsDir . '/Settings Test Channel/Season 2026', 0755, true);
        file_put_contents($downloadsDir . '/' . $existingRelativePath, 'real file contents');

        $existingVideo = Video::create([
            'channel_id' => $channel->id,
            'youtube_id' => 'settings_vid_existing',
            'title' => 'Video With Existing File',
            'published_at' => now(),
            'status' => 'completed',
            'file_path' => $existingRelativePath,
        ]);

        $response = $this->actingAs($user)->get('/settings/check-missing-videos');

        $response->assertStatus(200);
        $response->assertJsonFragment(['id' => $missingVideo->id]);

        $ids = collect($response->json())->pluck('id')->all();
        $this->assertContains($missingVideo->id, $ids);
        $this->assertNotContains($existingVideo->id, $ids);
    }

    public function test_clean_missing_videos_deletes_records_whose_file_is_absent_on_disk()
    {
        $user = User::factory()->create();

        $channel = Channel::create([
            'youtube_id' => 'UC_settings_clean_chan',
            'name' => 'Settings Test Channel',
            'url' => 'https://example.com/settings-clean',
        ]);

        $missingVideo = Video::create([
            'channel_id' => $channel->id,
            'youtube_id' => 'settings_vid_clean_missing',
            'title' => 'Video To Be Cleaned',
            'published_at' => now(),
            'status' => 'completed',
            'file_path' => 'Settings Test Channel/Season 2026/clean-missing-video.mp4',
        ]);

        $downloadsDir = Setting::getStoragePath();
        $existingRelativePath = 'Settings Test Channel/Season 2026/clean-existing-video.mp4';
        @mkdir($downloadsDir . '/Settings Test Channel/Season 2026', 0755, true);
        file_put_contents($downloadsDir . '/' . $existingRelativePath, 'real file contents');

        $existingVideo = Video::create([
            'channel_id' => $channel->id,
            'youtube_id' => 'settings_vid_clean_existing',
            'title' => 'Video To Be Kept',
            'published_at' => now(),
            'status' => 'completed',
            'file_path' => $existingRelativePath,
        ]);

        $response = $this->actingAs($user)->post('/settings/clean-missing-videos');

        $response->assertRedirect();
        $this->assertDatabaseMissing('videos', ['id' => $missingVideo->id]);
        $this->assertDatabaseHas('videos', ['id' => $existingVideo->id]);
    }
}
