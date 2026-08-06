<?php

namespace Tests\Feature;

use App\Jobs\CompressVideoJob;
use App\Models\Channel;
use App\Models\Setting;
use App\Models\User;
use App\Models\Video;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class VideoCompressionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Setting::set('ytdlp_delay_seconds', '0');

        if (! is_dir(storage_path('app/temp'))) {
            mkdir(storage_path('app/temp'), 0755, true);
        }
    }

    protected function tearDown(): void
    {
        $downloadsDir = Setting::getStoragePath();
        foreach (glob($downloadsDir.'/Compression *', GLOB_ONLYDIR) ?: [] as $dir) {
            exec('rm -rf '.escapeshellarg($dir));
        }

        parent::tearDown();
    }

    /**
     * Each video gets its own dedicated channel (named after its youtube_id), so two videos
     * created in the same test never show up on each other's "more from this channel" list.
     *
     * @return array{0: Channel, 1: Video, 2: string} [channel, video, full path to its file on disk]
     */
    private function makeDownloadedVideo(string $youtubeId, array $overrides = []): array
    {
        $channelName = "Compression Test Channel {$youtubeId}";

        $channel = Channel::create([
            'youtube_id' => "UC_{$youtubeId}",
            'name' => $channelName,
            'url' => "https://example.com/{$youtubeId}",
        ]);

        $downloadsDir = Setting::getStoragePath();
        $videoDir = $downloadsDir.'/'.$channelName.'/Season 2026';
        mkdir($videoDir, 0755, true);

        $relativeVideoPath = "{$channelName}/Season 2026/{$youtubeId}.mp4";
        $fullPath = $downloadsDir.'/'.$relativeVideoPath;
        file_put_contents($fullPath, 'original h264 video bytes');

        $video = Video::create(array_merge([
            'channel_id' => $channel->id,
            'youtube_id' => $youtubeId,
            'title' => 'Compression Test Video '.$youtubeId,
            'published_at' => now(),
            'duration' => 10,
            'status' => 'completed',
            'file_path' => $relativeVideoPath,
            'file_size' => filesize($fullPath),
            'downloaded_at' => now(),
        ], $overrides));

        return [$channel, $video, $fullPath];
    }

    /**
     * Writes a fake ffprobe that always reports $codec, mirroring DownloadNextVideoTest's mock
     * yt-dlp binary pattern.
     */
    private function mockFfprobe(string $codec): string
    {
        $mock = storage_path('app/temp/mock_ffprobe_'.uniqid().'.sh');
        file_put_contents($mock, "#!/bin/bash\necho '{$codec}'\nexit 0\n");
        chmod($mock, 0755);
        config(['services.ffprobe_path' => $mock]);

        return $mock;
    }

    /**
     * Writes a fake ffmpeg that emits a couple of -progress lines and writes dummy "compressed"
     * bytes to whatever path it's given as its final argument (the output path).
     */
    private function mockFfmpeg(bool $succeed = true): string
    {
        $mock = storage_path('app/temp/mock_ffmpeg_'.uniqid().'.sh');
        $body = $succeed
            ? <<<'BASH'
#!/bin/bash
output="${@: -1}"
echo "out_time=00:00:05.000"
echo "progress=continue"
echo "out_time=00:00:10.000"
echo "progress=end"
echo "compressed hevc video bytes" > "$output"
exit 0
BASH
            : <<<'BASH'
#!/bin/bash
echo "ffmpeg exploded" >&2
exit 1
BASH;
        file_put_contents($mock, $body);
        chmod($mock, 0755);
        config(['services.ffmpeg_path' => $mock]);

        return $mock;
    }

    public function test_compress_video_job_leaves_an_already_hevc_file_untouched()
    {
        [, $video, $fullPath] = $this->makeDownloadedVideo('already_hevc_vid');
        $originalContents = file_get_contents($fullPath);

        $this->mockFfprobe('hevc');
        $this->mockFfmpeg(); // should never be invoked

        CompressVideoJob::dispatchSync($video);

        $video->refresh();
        $this->assertSame('hevc', $video->video_codec);
        $this->assertSame('completed', $video->compression_status);
        $this->assertSame(100, $video->compression_progress_percent);
        $this->assertSame($originalContents, file_get_contents($fullPath));
    }

    public function test_compress_video_job_transcodes_a_non_hevc_file_and_swaps_it_in()
    {
        [, $video, $fullPath] = $this->makeDownloadedVideo('needs_compression_vid');

        $this->mockFfprobe('h264');
        $this->mockFfmpeg();

        CompressVideoJob::dispatchSync($video);

        $video->refresh();
        $this->assertSame('hevc', $video->video_codec);
        $this->assertSame('completed', $video->compression_status);
        $this->assertSame(100, $video->compression_progress_percent);
        $this->assertSame('compressed hevc video bytes'."\n", file_get_contents($fullPath));
        $this->assertSame(filesize($fullPath), $video->file_size);

        // No leftover temp/backup files.
        $this->assertFileDoesNotExist($fullPath.'.pre-compress.bak');
    }

    public function test_compress_video_job_marks_failure_and_leaves_original_untouched_when_ffmpeg_fails()
    {
        [, $video, $fullPath] = $this->makeDownloadedVideo('failing_compression_vid');
        $originalContents = file_get_contents($fullPath);

        $this->mockFfprobe('h264');
        $this->mockFfmpeg(succeed: false);

        CompressVideoJob::dispatchSync($video);

        $video->refresh();
        $this->assertSame('failed', $video->compression_status);
        $this->assertNotNull($video->compression_error);
        $this->assertNull($video->video_codec);
        $this->assertSame($originalContents, file_get_contents($fullPath));
    }

    public function test_optimize_button_is_shown_for_a_downloaded_non_hevc_video()
    {
        $user = User::factory()->create();
        [, $video] = $this->makeDownloadedVideo('non_hevc_button_vid');

        $response = $this->actingAs($user)->get('/videos/'.$video->id);
        $response->assertSee('class="video-optimize-btn', false);
        $response->assertSee('data-optimize-url="'.route('videos.optimize', $video).'"', false);
    }

    public function test_optimize_button_is_hidden_for_an_already_hevc_video()
    {
        $user = User::factory()->create();
        [, $video] = $this->makeDownloadedVideo('hevc_button_vid', ['video_codec' => 'hevc']);

        // Only this one video exists, so a bare assertDontSee for the button markup can't be
        // accidentally satisfied by some other video's card (e.g. a "suggested videos" list).
        // Matches the opening tag specifically (class="video-optimize-btn) rather than the bare
        // class name, since the latter also appears as a CSS selector string inside the page's
        // always-present polling/click-handler JS (_video-modals.blade.php).
        $response = $this->actingAs($user)->get('/videos/'.$video->id);
        $response->assertDontSee('class="video-optimize-btn', false);
    }

    public function test_optimize_button_is_disabled_while_a_compression_run_is_in_progress()
    {
        $user = User::factory()->create();
        [, $video] = $this->makeDownloadedVideo('in-progress_button_vid', ['compression_status' => 'processing']);

        $response = $this->actingAs($user)->get('/videos/'.$video->id);
        $response->assertSee('class="video-optimize-btn', false);
        $response->assertSee('Optimizing…');

        // The literal word "disabled" also appears sitewide as part of Tailwind's
        // "disabled:opacity-50" variant classes, so assertSee('disabled') alone would pass
        // regardless of whether the attribute is actually present — check the real HTML
        // attribute via regex instead.
        $this->assertMatchesRegularExpression(
            '/class="video-optimize-btn[^"]*"\s+disabled\s*>/',
            $response->getContent()
        );
    }

    public function test_optimize_endpoint_queues_compression_and_rejects_when_not_eligible()
    {
        Queue::fake();

        $user = User::factory()->create();
        [, $video] = $this->makeDownloadedVideo('optimize_endpoint_vid');

        $response = $this->actingAs($user)->postJson('/videos/'.$video->id.'/optimize');
        $response->assertOk();
        $video->refresh();
        $this->assertSame('queued', $video->compression_status);
        Queue::assertPushed(CompressVideoJob::class);

        // Already in progress: a second call is rejected instead of double-queuing.
        $response = $this->actingAs($user)->postJson('/videos/'.$video->id.'/optimize');
        $response->assertStatus(422);

        // Already HEVC: no action is offered.
        [, $hevcVideo] = $this->makeDownloadedVideo('optimize_hevc_vid', ['video_codec' => 'hevc']);
        $response = $this->actingAs($user)->postJson('/videos/'.$hevcVideo->id.'/optimize');
        $response->assertStatus(422);
    }

    public function test_compression_status_endpoint_reflects_the_videos_current_state()
    {
        $user = User::factory()->create();
        [, $video] = $this->makeDownloadedVideo('status_endpoint_vid', [
            'compression_status' => 'processing',
            'compression_progress_percent' => 42,
        ]);

        $response = $this->actingAs($user)->getJson('/videos/'.$video->id.'/compression-status');
        $response->assertOk();
        $response->assertJson([
            'compression_status' => 'processing',
            'compression_progress_percent' => 42,
            'is_hevc' => false,
        ]);
    }

    public function test_compression_setting_toggle_persists_and_defaults_to_disabled()
    {
        $this->assertFalse(Setting::compressionEnabled());

        $user = User::factory()->create();
        $this->actingAs($user)->post('/settings/compression-enabled', ['compression_enabled' => '1']);
        $this->assertTrue(Setting::compressionEnabled());

        $this->actingAs($user)->post('/settings/compression-enabled', []);
        $this->assertFalse(Setting::compressionEnabled());
    }

    public function test_download_does_not_queue_automatic_compression_when_the_setting_is_disabled()
    {
        Queue::fake();
        // Setting::compressionEnabled() defaults to false; left unset here on purpose.

        $channel = Channel::create([
            'youtube_id' => 'UC_compression_auto_chan',
            'name' => 'Compression Auto Channel',
            'url' => 'https://example.com/compressionauto',
        ]);

        $video = Video::create([
            'channel_id' => $channel->id,
            'youtube_id' => 'no_auto_compress_vid',
            'title' => 'Do Not Auto Compress Me',
            'published_at' => now(),
            'status' => 'pending',
        ]);

        $mockYtDlp = storage_path('app/temp/mock_ytdlp_no_compress.sh');
        file_put_contents($mockYtDlp, <<<'BASH'
#!/bin/bash
for arg in "$@"; do
    if [[ $arg == *video.* ]]; then
        out_dir=$(dirname "$arg")
        mkdir -p "$out_dir"
        echo "dummy video" > "$out_dir/video.mp4"
        echo "dummy thumb" > "$out_dir/video.jpg"
        echo "{}" > "$out_dir/video.info.json"
        exit 0
    fi
done
exit 1
BASH);
        chmod($mockYtDlp, 0755);
        config(['services.ytdlp_path' => $mockYtDlp]);

        Artisan::call('videos:download');

        $video->refresh();
        $this->assertSame('completed', $video->status);
        $this->assertNull($video->compression_status);
        Queue::assertNotPushed(CompressVideoJob::class);
    }

    public function test_download_queues_automatic_compression_when_the_setting_is_enabled()
    {
        Queue::fake();
        Setting::set('compression_enabled', '1');

        $channel = Channel::create([
            'youtube_id' => 'UC_compression_auto_chan',
            'name' => 'Compression Auto Channel',
            'url' => 'https://example.com/compressionauto',
        ]);

        $video = Video::create([
            'channel_id' => $channel->id,
            'youtube_id' => 'auto_compress_vid',
            'title' => 'Auto Compress Me',
            'published_at' => now(),
            'status' => 'pending',
        ]);

        $mockYtDlp = storage_path('app/temp/mock_ytdlp_compress.sh');
        file_put_contents($mockYtDlp, <<<'BASH'
#!/bin/bash
for arg in "$@"; do
    if [[ $arg == *video.* ]]; then
        out_dir=$(dirname "$arg")
        mkdir -p "$out_dir"
        echo "dummy video" > "$out_dir/video.mp4"
        echo "dummy thumb" > "$out_dir/video.jpg"
        echo "{}" > "$out_dir/video.info.json"
        exit 0
    fi
done
exit 1
BASH);
        chmod($mockYtDlp, 0755);
        config(['services.ytdlp_path' => $mockYtDlp]);

        Artisan::call('videos:download');

        $video->refresh();
        $this->assertSame('completed', $video->status);
        $this->assertSame('queued', $video->compression_status);
        Queue::assertPushed(CompressVideoJob::class);
    }
}
