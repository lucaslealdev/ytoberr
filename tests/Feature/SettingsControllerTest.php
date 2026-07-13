<?php

namespace Tests\Feature;

use App\Models\Channel;
use App\Models\Setting;
use App\Models\User;
use App\Models\Video;
use App\Models\Warning;
use App\Models\YtDlpCache;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SettingsControllerTest extends TestCase
{
    use RefreshDatabase;

    private string $mockYtDlp;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();

        // The settings page shells out to yt-dlp for its version on every request;
        // stub it out so the suite doesn't depend on the real binary being installed.
        $this->mockYtDlp = storage_path('app/temp/mock_ytdlp_settings.sh');
        file_put_contents($this->mockYtDlp, "#!/bin/bash\necho '2026.01.01'\n");
        chmod($this->mockYtDlp, 0755);
        config(['services.ytdlp_path' => $this->mockYtDlp]);
    }

    protected function tearDown(): void
    {
        if (file_exists($this->mockYtDlp)) {
            unlink($this->mockYtDlp);
        }

        $downloadsDir = Setting::getStoragePath();
        if (file_exists($downloadsDir.'/Settings Test Channel')) {
            exec('rm -rf '.escapeshellarg($downloadsDir.'/Settings Test Channel'));
        }

        parent::tearDown();
    }

    public function test_index_renders_with_ytdlp_version_cache_count_and_queued_videos()
    {
        $user = User::factory()->create();

        $expectedVersion = '2026.01.01';

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

    public function test_index_shows_update_notice_when_a_newer_version_is_available()
    {
        Http::fake([
            'api.github.com/*' => Http::response([
                ['name' => 'v999.0.0'],
            ]),
        ]);

        $user = User::factory()->create();

        $response = $this->actingAs($user)->get('/settings');

        $response->assertStatus(200);
        $response->assertSee('Update available: v999.0.0');
        $response->assertSee('docker compose pull');
    }

    public function test_index_does_not_show_update_notice_when_already_on_the_latest_version()
    {
        Http::fake([
            'api.github.com/*' => Http::response([
                ['name' => 'v'.config('app.version')],
            ]),
        ]);

        $user = User::factory()->create();

        $response = $this->actingAs($user)->get('/settings');

        $response->assertStatus(200);
        $response->assertDontSee('Update available');
    }

    public function test_index_does_not_show_update_notice_when_github_is_unreachable()
    {
        Http::fake([
            'api.github.com/*' => Http::failedConnection(),
        ]);

        $user = User::factory()->create();

        $response = $this->actingAs($user)->get('/settings');

        $response->assertStatus(200);
        $response->assertDontSee('Update available');
    }

    public function test_index_lists_warnings_with_their_details()
    {
        $user = User::factory()->create();

        Warning::log('download_failed_permanently', 'Video X permanently failed after 3 attempts.', 'raw yt-dlp output here');

        $response = $this->actingAs($user)->get('/settings');

        $response->assertStatus(200);
        $response->assertSee('Video X permanently failed after 3 attempts.');
        $response->assertSee('download_failed_permanently');
        $response->assertSee('raw yt-dlp output here');
    }

    public function test_index_shows_no_warnings_message_when_there_are_none()
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get('/settings');

        $response->assertStatus(200);
        $response->assertSee('No warnings. Everything looks healthy.');
    }

    public function test_sidebar_shows_a_red_badge_with_the_warnings_count_next_to_settings()
    {
        $user = User::factory()->create();
        Warning::log('queue_suspended', 'Suspending all pending downloads.');
        Warning::log('channel_check_failed', 'Failed to check channel: Some Channel');

        $response = $this->actingAs($user)->get('/');

        $response->assertStatus(200);
        $response->assertSee('id="sidebar"', false);
        $this->assertMatchesRegularExpression(
            '/bg-red-600[^>]*>\s*2\s*</s',
            $response->getContent()
        );
    }

    public function test_sidebar_does_not_show_a_warnings_badge_when_there_are_no_warnings()
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get('/');

        $response->assertStatus(200);
        $response->assertDontSee('bg-red-600', false);
    }

    public function test_can_dismiss_a_warning()
    {
        $user = User::factory()->create();
        $warning = Warning::log('queue_suspended', 'Suspending all pending downloads.');

        $response = $this->actingAs($user)->delete("/settings/warnings/{$warning->id}");

        $response->assertRedirect();
        $this->assertDatabaseMissing('warnings', ['id' => $warning->id]);
    }

    public function test_dismissing_a_warning_does_not_prevent_the_same_problem_from_being_logged_again()
    {
        $user = User::factory()->create();
        $first = Warning::log('queue_suspended', 'Suspending all pending downloads.');

        $this->actingAs($user)->delete("/settings/warnings/{$first->id}");
        $this->assertDatabaseMissing('warnings', ['id' => $first->id]);

        $second = Warning::log('queue_suspended', 'Suspending all pending downloads.');

        $this->assertDatabaseHas('warnings', ['id' => $second->id]);
        $this->assertSame(1, Warning::count());
    }

    public function test_warning_linked_to_a_video_shows_a_retry_download_button()
    {
        $user = User::factory()->create();
        $channel = Channel::create([
            'youtube_id' => 'UC_warning_retry_chan',
            'name' => 'Warning Retry Channel',
            'url' => 'https://example.com/warningretry',
        ]);
        $video = Video::create([
            'channel_id' => $channel->id,
            'youtube_id' => 'warning_retry_vid',
            'title' => 'Warning Retry Video',
            'published_at' => now(),
            'status' => 'failed',
        ]);
        Warning::log('download_failed_permanently', 'Video permanently failed.', null, $video->id);

        $response = $this->actingAs($user)->get('/settings');

        $response->assertStatus(200);
        $response->assertSee('Retry Download');
        $response->assertSee('action="'.route('videos.retry', $video).'"', false);
    }

    public function test_warning_without_a_video_does_not_show_a_retry_download_button()
    {
        $user = User::factory()->create();
        Warning::log('queue_suspended', 'Suspending all pending downloads.');

        $response = $this->actingAs($user)->get('/settings');

        $response->assertStatus(200);
        $response->assertDontSee('Retry Download');
    }

    public function test_update_ytdlp_delay_persists_the_setting()
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->post('/settings/ytdlp-delay', [
            'ytdlp_delay_seconds' => 15,
        ]);

        $response->assertRedirect();
        $this->assertSame(15, Setting::ytdlpDelaySeconds());
    }

    public function test_update_ytdlp_delay_rejects_a_negative_value()
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->post('/settings/ytdlp-delay', [
            'ytdlp_delay_seconds' => -1,
        ]);

        $response->assertSessionHasErrors('ytdlp_delay_seconds');
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
        @mkdir($downloadsDir.'/Settings Test Channel/Season 2026', 0755, true);
        file_put_contents($downloadsDir.'/'.$existingRelativePath, 'real file contents');

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
        @mkdir($downloadsDir.'/Settings Test Channel/Season 2026', 0755, true);
        file_put_contents($downloadsDir.'/'.$existingRelativePath, 'real file contents');

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
