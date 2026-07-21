<?php

namespace Tests\Feature;

use App\Models\Channel;
use App\Models\Setting;
use App\Models\User;
use App\Models\Video;
use App\Models\Warning;
use App\Models\YtDlpCache;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
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

        // The /settings/ytdlp-version endpoint shells out to yt-dlp for its version;
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

        $cookiesPath = storage_path('app/cookies.txt');
        if (file_exists($cookiesPath)) {
            unlink($cookiesPath);
        }

        foreach ([
            '/tmp/ytoberr-test-storage-path',
            '/tmp/ytoberr-test-storage-path-writable-parent',
        ] as $testDir) {
            if (file_exists($testDir)) {
                exec('rm -rf '.escapeshellarg($testDir));
            }
        }

        parent::tearDown();
    }

    public function test_index_renders_with_cache_count_without_blocking_on_ytdlp_version()
    {
        $user = User::factory()->create();

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

        // Point at a binary that would hang/fail if index() ever shelled out to it directly,
        // proving the page itself no longer depends on yt-dlp's (slow) startup time.
        config(['services.ytdlp_path' => '/nonexistent/yt-dlp']);

        $response = $this->actingAs($user)->get('/settings');

        $response->assertStatus(200);
        $response->assertSee('id="ytdlp-version"', false);

        $this->assertMatchesRegularExpression(
            '/Total cached yt-dlp metadata queries:.*?<span[^>]*>2<\/span>/s',
            $response->getContent()
        );
    }

    public function test_ytdlp_version_endpoint_returns_the_version_reported_by_the_binary()
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get('/settings/ytdlp-version');

        $response->assertStatus(200);
        $response->assertExactJson(['version' => '2026.01.01']);
    }

    public function test_ytdlp_version_endpoint_reports_unknown_when_the_binary_is_unavailable()
    {
        $user = User::factory()->create();

        config(['services.ytdlp_path' => '/nonexistent/yt-dlp']);

        $response = $this->actingAs($user)->get('/settings/ytdlp-version');

        $response->assertStatus(200);
        $response->assertExactJson(['version' => 'Unknown']);
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

    public function test_can_clear_all_warnings_at_once()
    {
        $user = User::factory()->create();
        Warning::log('queue_suspended', 'Suspending all pending downloads.');
        Warning::log('channel_check_failed', 'Failed to check channel: Some Channel');

        $indexResponse = $this->actingAs($user)->get('/settings');
        $indexResponse->assertSee('Clear all');

        $response = $this->actingAs($user)->delete('/settings/warnings');

        $response->assertRedirect();
        $this->assertSame(0, Warning::count());
    }

    public function test_clear_all_button_is_hidden_when_there_are_no_warnings()
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get('/settings');

        $response->assertStatus(200);
        $response->assertDontSee('Clear all');
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

    public function test_update_storage_path_creates_the_directory_when_it_does_not_exist_yet()
    {
        $user = User::factory()->create();

        $newPath = '/tmp/ytoberr-test-storage-path';
        $this->assertDirectoryDoesNotExist($newPath);

        $response = $this->actingAs($user)->post('/settings/storage-path', [
            'storage_path' => $newPath,
        ]);

        $response->assertRedirect();
        $response->assertSessionHasNoErrors();
        $this->assertDirectoryExists($newPath);
        $this->assertEquals($newPath, Setting::getStoragePath());
    }

    public function test_update_storage_path_rejects_a_path_whose_parent_is_not_writable()
    {
        $user = User::factory()->create();

        $readOnlyParent = '/tmp/ytoberr-test-storage-path-writable-parent';
        mkdir($readOnlyParent, 0555, true);

        try {
            $response = $this->actingAs($user)->post('/settings/storage-path', [
                'storage_path' => $readOnlyParent.'/downloads',
            ]);

            $response->assertSessionHasErrors('storage_path');
            $this->assertNotEquals($readOnlyParent.'/downloads', Setting::getStoragePath());
        } finally {
            chmod($readOnlyParent, 0755);
        }
    }

    public function test_update_storage_path_rejects_an_existing_but_unwritable_directory()
    {
        $user = User::factory()->create();

        $unwritableDir = '/tmp/ytoberr-test-storage-path';
        mkdir($unwritableDir, 0555, true);

        try {
            $response = $this->actingAs($user)->post('/settings/storage-path', [
                'storage_path' => $unwritableDir,
            ]);

            $response->assertSessionHasErrors('storage_path');
            $this->assertNotEquals($unwritableDir, Setting::getStoragePath());
        } finally {
            chmod($unwritableDir, 0755);
        }
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

    public function test_settings_page_shows_no_cookies_configured_by_default()
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get('/settings');

        $response->assertStatus(200);
        $response->assertSee('No cookies configured');
    }

    public function test_can_upload_a_valid_cookies_file()
    {
        $user = User::factory()->create();

        $content = "# Netscape HTTP Cookie File\n.youtube.com\tTRUE\t/\tTRUE\t1799999999\tSID\tabc123\n";
        $file = UploadedFile::fake()->createWithContent('cookies.txt', $content);

        $response = $this->actingAs($user)->post('/settings/cookies', [
            'cookies_file' => $file,
        ]);

        $response->assertRedirect();
        $response->assertSessionHasNoErrors();
        $this->assertFileExists(storage_path('app/cookies.txt'));
        $this->assertSame($content, file_get_contents(storage_path('app/cookies.txt')));

        $settingsResponse = $this->actingAs($user)->get('/settings');
        $settingsResponse->assertSee('Cookies configured');
    }

    public function test_can_paste_valid_cookies_text()
    {
        $user = User::factory()->create();

        $content = "# HTTP Cookie File\n.youtube.com\tTRUE\t/\tTRUE\t1799999999\tSID\tabc123\n";

        $response = $this->actingAs($user)->post('/settings/cookies', [
            'cookies_text' => $content,
        ]);

        $response->assertRedirect();
        $response->assertSessionHasNoErrors();
        // The global TrimStrings middleware trims the outer whitespace of form fields
        // (including a trailing newline), which doesn't affect the file's validity.
        $this->assertSame(trim($content), file_get_contents(storage_path('app/cookies.txt')));
    }

    public function test_normalizes_windows_line_endings_when_saving_cookies()
    {
        $user = User::factory()->create();

        $content = "# Netscape HTTP Cookie File\r\n.youtube.com\tTRUE\t/\tTRUE\t1799999999\tSID\tabc123\r\n";

        $response = $this->actingAs($user)->post('/settings/cookies', [
            'cookies_text' => $content,
        ]);

        $response->assertRedirect();
        $response->assertSessionHasNoErrors();
        $this->assertStringNotContainsString("\r", file_get_contents(storage_path('app/cookies.txt')));
    }

    public function test_rejects_cookies_content_without_the_required_first_line()
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->post('/settings/cookies', [
            'cookies_text' => "this is not a cookies file\nrandom text here\n",
        ]);

        $response->assertSessionHasErrors('cookies_file');
        $this->assertFileDoesNotExist(storage_path('app/cookies.txt'));
    }

    public function test_rejects_the_cookies_form_when_neither_file_nor_text_is_provided()
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->post('/settings/cookies', []);

        $response->assertSessionHasErrors('cookies_file');
    }

    public function test_can_remove_configured_cookies()
    {
        $user = User::factory()->create();
        file_put_contents(storage_path('app/cookies.txt'), "# Netscape HTTP Cookie File\n");

        $response = $this->actingAs($user)->delete('/settings/cookies');

        $response->assertRedirect();
        $this->assertFileDoesNotExist(storage_path('app/cookies.txt'));
    }
}
