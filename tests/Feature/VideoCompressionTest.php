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
    private function makeDownloadedVideo(string $youtubeId, array $overrides = [], string $extension = 'mp4'): array
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

        $relativeVideoPath = "{$channelName}/Season 2026/{$youtubeId}.{$extension}";
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
     * bytes to whatever path it's given as its final argument (the output path). The output is
     * deliberately shorter than makeDownloadedVideo()'s ~26-byte fixture content, so the
     * "smaller than the original" check doesn't discard it — tests exercising that specific
     * discard behavior use mockFfmpegWithLargerOutput() instead.
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
printf 'hevc' > "$output"
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

    /**
     * Writes a fake ffmpeg whose output is larger than makeDownloadedVideo()'s ~26-byte fixture
     * content — simulating a source already using an efficient codec (VP9/AV1) where the HEVC
     * re-encode ends up bigger, not smaller, than the original.
     */
    private function mockFfmpegWithLargerOutput(): string
    {
        $mock = storage_path('app/temp/mock_ffmpeg_'.uniqid().'.sh');
        file_put_contents($mock, <<<'BASH'
#!/bin/bash
output="${@: -1}"
echo "progress=end"
printf 'this compressed output is deliberately larger than the tiny test fixture' > "$output"
exit 0
BASH);
        chmod($mock, 0755);
        config(['services.ffmpeg_path' => $mock]);

        return $mock;
    }

    public function test_compress_video_job_declares_a_timeout_generous_enough_for_a_multi_hour_encode()
    {
        [, $video] = $this->makeDownloadedVideo('timeout_property_vid');
        $job = new CompressVideoJob($video);

        // Laravel's queue worker kills a job after its own $timeout (default 60s if unset),
        // independent of ffmpeg's own internal process timeout — this must be generous enough
        // that the worker never kills a legitimately still-running multi-hour encode.
        $this->assertGreaterThanOrEqual(6 * 3600, $job->timeout);
        $this->assertTrue($job->failOnTimeout);
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
        $newFullPath = Setting::getStoragePath().'/'.$video->file_path;

        $this->assertSame('hevc', $video->video_codec);
        $this->assertSame('completed', $video->compression_status);
        $this->assertSame(100, $video->compression_progress_percent);

        // The source was .mp4, but the output always targets an .mkv container, so both the
        // stored file_path and the file on disk move to the new extension.
        $this->assertStringEndsWith('.mkv', $video->file_path);
        $this->assertFileDoesNotExist($fullPath);
        $this->assertSame('hevc', file_get_contents($newFullPath));
        $this->assertSame(filesize($newFullPath), $video->file_size);

        // No leftover temp/backup files.
        $this->assertFileDoesNotExist($fullPath.'.pre-compress.bak');
    }

    public function test_compress_video_job_moves_a_webm_source_to_mkv_since_webm_cannot_hold_hevc()
    {
        [, $video, $fullPath] = $this->makeDownloadedVideo('webm_source_vid', extension: 'webm');

        // VP8 (unlike VP9/AV1) isn't flagged as "already efficient", so this exercises the real
        // transcode + container-swap path rather than the early skip.
        $this->mockFfprobe('vp8');
        $this->mockFfmpeg();

        CompressVideoJob::dispatchSync($video);

        $video->refresh();
        $this->assertSame('completed', $video->compression_status);
        $this->assertNull($video->compression_error);
        $this->assertStringEndsWith('.mkv', $video->file_path);
        $this->assertFileDoesNotExist($fullPath);
        $this->assertFileExists(Setting::getStoragePath().'/'.$video->file_path);
    }

    public function test_compress_video_job_keeps_the_original_when_the_hevc_reencode_is_not_smaller()
    {
        [, $video, $fullPath] = $this->makeDownloadedVideo('larger_after_compress_vid');
        $originalContents = file_get_contents($fullPath);
        $originalSize = $video->file_size;

        // A borderline H.264 source (not on the "already efficient" list, so it still goes
        // through a real transcode attempt) whose HEVC re-encode just happens to come out bigger
        // this time — the result should be discarded and the original kept.
        $this->mockFfprobe('h264');
        $this->mockFfmpegWithLargerOutput();

        CompressVideoJob::dispatchSync($video);

        $video->refresh();
        $this->assertSame('skipped', $video->compression_status);
        $this->assertNotNull($video->compression_error);
        $this->assertSame('h264', $video->video_codec);
        $this->assertNotSame('hevc', $video->video_codec);
        $this->assertSame($originalContents, file_get_contents($fullPath));
        $this->assertSame($originalSize, $video->file_size);

        // needsOptimization() should still offer the button again (this isn't HEVC and h264
        // isn't an "already efficient" codec), and the status shouldn't be mistaken for "still
        // running".
        $this->assertFalse($video->isCompressing());
        $this->assertTrue($video->needsOptimization());

        // No leftover temp/backup files.
        $this->assertFileDoesNotExist($fullPath.'.pre-compress.bak');
    }

    public function test_compress_video_job_skips_the_transcode_entirely_for_an_already_efficient_codec()
    {
        [, $video, $fullPath] = $this->makeDownloadedVideo('efficient_codec_vid');
        $originalContents = file_get_contents($fullPath);

        // Legacy-video fallback: video_codec wasn't known at queue time (predates download-time
        // detection), so the job itself has to probe it — and, finding VP9, must skip the actual
        // ffmpeg transcode entirely rather than run it just to prove it wouldn't shrink.
        $this->mockFfprobe('vp9');
        $sentinel = storage_path('app/temp/ffmpeg_was_invoked_'.uniqid());
        file_put_contents(storage_path('app/temp/mock_ffmpeg_sentinel.sh'), <<<BASH
#!/bin/bash
touch {$sentinel}
exit 1
BASH);
        chmod(storage_path('app/temp/mock_ffmpeg_sentinel.sh'), 0755);
        config(['services.ffmpeg_path' => storage_path('app/temp/mock_ffmpeg_sentinel.sh')]);

        CompressVideoJob::dispatchSync($video);

        $this->assertFileDoesNotExist($sentinel, 'ffmpeg should never have been invoked for an already-efficient codec.');

        $video->refresh();
        $this->assertSame('skipped', $video->compression_status);
        $this->assertNotNull($video->compression_error);
        $this->assertSame('vp9', $video->video_codec);
        $this->assertSame($originalContents, file_get_contents($fullPath));
        $this->assertFalse($video->needsOptimization());
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

    public function test_optimize_button_is_hidden_for_an_already_efficient_codec()
    {
        $user = User::factory()->create();
        [, $video] = $this->makeDownloadedVideo('vp9_button_vid', ['video_codec' => 'vp9']);

        $this->assertFalse($video->needsOptimization());

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

        // Already an efficient codec (VP9/AV1): no action is offered either.
        [, $vp9Video] = $this->makeDownloadedVideo('optimize_vp9_vid', ['video_codec' => 'vp9']);
        $response = $this->actingAs($user)->postJson('/videos/'.$vp9Video->id.'/optimize');
        $response->assertStatus(422);
        Queue::assertNotPushed(CompressVideoJob::class, fn ($job) => $job->video->is($vp9Video));
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
            'needs_optimization' => true,
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
