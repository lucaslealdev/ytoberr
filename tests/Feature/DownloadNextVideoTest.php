<?php

namespace Tests\Feature;

use App\Models\Channel;
use App\Models\Setting;
use App\Models\Video;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Sleep;
use Tests\TestCase;

class DownloadNextVideoTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');

        // yt-dlp is always mocked in this test class; the production safety delay between
        // videos in the queue-draining loop would only slow the suite down without adding
        // value. Tests that specifically exercise the delay override it below.
        Setting::set('ytdlp_delay_seconds', '0');
    }

    protected function tearDown(): void
    {
        // DownloadNextVideo writes to the real downloads dir (not the faked disk), so clean up after ourselves.
        $downloadsDir = Setting::getStoragePath();
        if (file_exists($downloadsDir.'/Space Channel')) {
            exec('rm -rf '.escapeshellarg($downloadsDir.'/Space Channel'));
        }

        parent::tearDown();
    }

    public function test_downloader_successfully_downloads_video()
    {
        $channel = Channel::create([
            'youtube_id' => 'UC_test_chan',
            'name' => 'Space Channel',
            'url' => 'https://example.com/space',
            'description' => 'A channel about space exploration.',
        ]);

        // Seed a fake channel poster so we can assert it gets copied into the Plex show folder.
        Storage::disk('public')->put('channels/'.$channel->id.'/poster.jpg', 'fake-poster-bytes');

        $video = Video::create([
            'channel_id' => $channel->id,
            'youtube_id' => 'space_vid_123',
            'title' => 'Landing on Mars',
            'description' => 'Humanity lands on Mars for the first time.',
            'published_at' => '2026-07-10 12:00:00',
            'status' => 'pending',
        ]);

        // Create a mock yt-dlp bash wrapper that writes dummy files
        $mockYtDlp = storage_path('app/temp/mock_ytdlp_download.sh');
        file_put_contents($mockYtDlp, <<<'BASH'
#!/bin/bash
# Find the --output argument value and write dummy files next to it
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

        // Mock config for yt-dlp path to avoid real execution
        config(['services.ytdlp_path' => $mockYtDlp]);

        // Execute command
        Artisan::call('videos:download');

        // Confirm database records are correctly updated
        $video->refresh();
        $this->assertEquals('completed', $video->status);
        $this->assertNotNull($video->file_path);

        // Assert relative file path structure targets Plex:
        // {downloads_dir}/{channel}/Season {YYYY}/{filename}.{ext}
        $this->assertStringContainsString('Space Channel/Season 2026', $video->file_path);
        $this->assertStringContainsString('Landing on Mars [space_vid_123].mp4', $video->file_path);

        // Confirm thumbnail is copied as a local asset, named to exactly match the video's own
        // filename (Plex's Local Media Assets convention), not a "-thumb" suffixed name.
        $this->assertNotNull($video->thumbnail_path);
        $this->assertStringContainsString('Landing on Mars [space_vid_123].jpg', $video->thumbnail_path);
        $this->assertStringNotContainsString('-thumb.jpg', $video->thumbnail_path);

        // Confirm Plex local assets were written
        $downloadsDir = Setting::getStoragePath();
        $channelDir = $downloadsDir.'/Space Channel';

        $this->assertFileExists($channelDir.'/tvshow.nfo');
        $tvshowXml = simplexml_load_file($channelDir.'/tvshow.nfo');
        $this->assertEquals('Space Channel', (string) $tvshowXml->title);
        $this->assertEquals('A channel about space exploration.', (string) $tvshowXml->plot);

        $this->assertFileExists($channelDir.'/poster.jpg');
        $this->assertEquals('fake-poster-bytes', file_get_contents($channelDir.'/poster.jpg'));

        $videoNfoPath = $downloadsDir.'/'.str_replace('.mp4', '.nfo', $video->file_path);
        $this->assertFileExists($videoNfoPath);
        $videoXml = simplexml_load_file($videoNfoPath);
        $this->assertEquals('Landing on Mars', (string) $videoXml->title);
        $this->assertEquals('Humanity lands on Mars for the first time.', (string) $videoXml->plot);
        $this->assertEquals('2026', (string) $videoXml->season);
        // "0710" (upload month+day) + "99" (upload_date_index, defaults to 99 when this is
        // the only video from this channel on this date).
        $this->assertEquals('071099', (string) $videoXml->episode);
        $this->assertEquals('space_vid_123', (string) $videoXml->uniqueid);

        unlink($mockYtDlp);
    }

    public function test_downloader_gives_same_day_videos_distinct_episode_numbers()
    {
        // Regression test: two videos from the same channel uploaded on the same calendar date
        // must not collide into the same s{year}e{monthday} Plex episode slot.
        $channel = Channel::create([
            'youtube_id' => 'UC_test_chan_2',
            'name' => 'News Channel',
            'url' => 'https://example.com/news',
        ]);

        $videoOne = Video::create([
            'channel_id' => $channel->id,
            'youtube_id' => 'news_vid_1',
            'title' => 'Morning Update',
            'published_at' => '2026-07-11 08:00:00',
            'status' => 'pending',
        ]);
        $videoTwo = Video::create([
            'channel_id' => $channel->id,
            'youtube_id' => 'news_vid_2',
            'title' => 'Evening Update',
            'published_at' => '2026-07-11 20:00:00',
            'status' => 'pending',
        ]);

        $this->assertEquals(99, $videoOne->upload_date_index);
        $this->assertEquals(98, $videoTwo->upload_date_index);

        $mockYtDlp = storage_path('app/temp/mock_ytdlp_news.sh');
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

        // A single videos:download invocation now drains the whole pending queue (oldest
        // created_at first), one video at a time.
        Artisan::call('videos:download');

        $videoOne->refresh();
        $videoTwo->refresh();

        $this->assertStringContainsString('s2026e071199', $videoOne->file_path);
        $this->assertStringContainsString('s2026e071198', $videoTwo->file_path);
        $this->assertNotEquals($videoOne->file_path, $videoTwo->file_path);

        $downloadsDir = Setting::getStoragePath();
        $videoOneNfo = simplexml_load_file($downloadsDir.'/'.str_replace('.mp4', '.nfo', $videoOne->file_path));
        $videoTwoNfo = simplexml_load_file($downloadsDir.'/'.str_replace('.mp4', '.nfo', $videoTwo->file_path));

        $this->assertEquals('071199', (string) $videoOneNfo->episode);
        $this->assertEquals('071198', (string) $videoTwoNfo->episode);

        unlink($mockYtDlp);
        exec('rm -rf '.escapeshellarg($downloadsDir.'/News Channel'));
    }

    public function test_downloader_handles_permanently_unavailable_videos()
    {
        $channel = Channel::create([
            'youtube_id' => 'UC_test_chan',
            'name' => 'Space Channel',
            'url' => 'https://example.com/space',
        ]);

        $video = Video::create([
            'channel_id' => $channel->id,
            'youtube_id' => 'space_private',
            'title' => 'Secret Video',
            'published_at' => '2026-07-10 12:00:00',
            'status' => 'pending',
        ]);

        // Mock yt-dlp shell returning "Private Video" error code 1
        $mockYtDlp = storage_path('app/temp/mock_ytdlp_private.sh');
        file_put_contents($mockYtDlp, '#!/bin/bash
echo "ERROR: [youtube] space_private: Private video. Sign in if you have been granted access to this video"
exit 1
');
        chmod($mockYtDlp, 0755);
        config(['services.ytdlp_path' => $mockYtDlp]);

        Artisan::call('videos:download');

        $video->refresh();
        $this->assertEquals('failed', $video->status);
        $this->assertTrue((bool) $video->prevent_download);
        $this->assertEquals('Private video', $video->unavailable_reason);
        $this->assertStringContainsString('Permanently unavailable', $video->last_error);

        unlink($mockYtDlp);
    }

    public function test_downloader_suspends_queue_on_three_consecutive_failures()
    {
        $channel = Channel::create([
            'youtube_id' => 'UC_test_chan',
            'name' => 'Space Channel',
            'url' => 'https://example.com/space',
        ]);

        // Create 4 pending videos
        $v1 = Video::create(['channel_id' => $channel->id, 'youtube_id' => 'v1', 'title' => 'V1', 'published_at' => now(), 'status' => 'pending']);
        $v2 = Video::create(['channel_id' => $channel->id, 'youtube_id' => 'v2', 'title' => 'V2', 'published_at' => now(), 'status' => 'pending']);
        $v3 = Video::create(['channel_id' => $channel->id, 'youtube_id' => 'v3', 'title' => 'V3', 'published_at' => now(), 'status' => 'pending']);
        $v4 = Video::create(['channel_id' => $channel->id, 'youtube_id' => 'v4', 'title' => 'V4', 'published_at' => now(), 'status' => 'pending']);

        // Mock yt-dlp shell always returning error
        $mockYtDlp = storage_path('app/temp/mock_ytdlp_error.sh');
        file_put_contents($mockYtDlp, '#!/bin/bash
echo "ERROR: General Connection Error"
exit 1
');
        chmod($mockYtDlp, 0755);
        config(['services.ytdlp_path' => $mockYtDlp]);

        // Reset consecutive failures to 0
        Setting::set('consecutive_failures', '0');

        // A single invocation now drains the whole queue: v1 fails (failures = 1), v2 fails
        // (failures = 2), v3 fails (failures = 3, suspending the queue), at which point v4
        // (and any other remaining pending videos) are marked failed by the suspension itself.
        Artisan::call('videos:download');

        // On the third failure, the queue suspends, so v4 and any remaining pending videos are marked as failed
        $v1->refresh();
        $v2->refresh();
        $v3->refresh();
        $v4->refresh();

        $this->assertEquals('failed', $v1->status);
        $this->assertEquals('failed', $v2->status);
        $this->assertEquals('failed', $v3->status);
        $this->assertEquals('failed', $v4->status);
        $this->assertStringContainsString('Queue suspended', $v4->last_error);
        $this->assertDatabaseHas('warnings', ['source' => 'queue_suspended']);

        unlink($mockYtDlp);
    }

    public function test_downloader_logs_a_warning_and_fails_the_video_when_copy_fails()
    {
        // Regression test: a copy() failure (disk full, permissions, ...) must not leave the
        // video silently marked "completed" — it must fail visibly and log a Warning.
        $channel = Channel::create([
            'youtube_id' => 'UC_copy_fail_chan',
            'name' => 'Copy Fail Channel',
            'url' => 'https://example.com/copyfail',
        ]);

        $video = Video::create([
            'channel_id' => $channel->id,
            'youtube_id' => 'copy_fail_vid',
            'title' => 'Copy Fail Video',
            'published_at' => '2026-07-10 12:00:00',
            'status' => 'pending',
        ]);

        // Pre-create the target directory read-only so copy() into it fails with a
        // permission error, without needing to actually fill up the disk.
        $downloadsDir = Setting::getStoragePath();
        $targetDir = $downloadsDir.'/Copy Fail Channel/Season 2026';
        mkdir($targetDir, 0755, true);
        chmod($targetDir, 0555);

        $mockYtDlp = storage_path('app/temp/mock_ytdlp_copyfail.sh');
        file_put_contents($mockYtDlp, <<<'BASH'
#!/bin/bash
for arg in "$@"; do
    if [[ $arg == *video.* ]]; then
        out_dir=$(dirname "$arg")
        mkdir -p "$out_dir"
        echo "dummy video" > "$out_dir/video.mp4"
        exit 0
    fi
done
exit 1
BASH);
        chmod($mockYtDlp, 0755);
        config(['services.ytdlp_path' => $mockYtDlp]);

        try {
            Artisan::call('videos:download');

            $video->refresh();
            $this->assertNotEquals('completed', $video->status);
            $this->assertDatabaseHas('warnings', ['source' => 'download_copy_failed', 'video_id' => $video->id]);
        } finally {
            chmod($targetDir, 0755);
            exec('rm -rf '.escapeshellarg($downloadsDir.'/Copy Fail Channel'));
            unlink($mockYtDlp);
        }
    }

    public function test_downloader_logs_a_warning_when_a_video_permanently_fails_after_max_retries()
    {
        $channel = Channel::create([
            'youtube_id' => 'UC_test_chan',
            'name' => 'Space Channel',
            'url' => 'https://example.com/space',
        ]);

        $video = Video::create([
            'channel_id' => $channel->id,
            'youtube_id' => 'retry_exhausted_vid',
            'title' => 'Retry Exhausted Video',
            'published_at' => now(),
            'status' => 'pending',
            'retries' => 2,
        ]);

        $mockYtDlp = storage_path('app/temp/mock_ytdlp_generic_error.sh');
        file_put_contents($mockYtDlp, '#!/bin/bash
echo "ERROR: General Connection Error"
exit 1
');
        chmod($mockYtDlp, 0755);
        config(['services.ytdlp_path' => $mockYtDlp]);

        Artisan::call('videos:download');

        $video->refresh();
        $this->assertEquals('failed', $video->status);
        $this->assertDatabaseHas('warnings', ['source' => 'download_failed_permanently', 'video_id' => $video->id]);

        unlink($mockYtDlp);
    }

    public function test_downloader_passes_the_configured_sleep_delay_to_ytdlp()
    {
        Setting::set('ytdlp_delay_seconds', '7');

        $channel = Channel::create([
            'youtube_id' => 'UC_test_chan',
            'name' => 'Space Channel',
            'url' => 'https://example.com/space',
        ]);

        $video = Video::create([
            'channel_id' => $channel->id,
            'youtube_id' => 'delay_vid',
            'title' => 'Delay Video',
            'published_at' => now(),
            'status' => 'pending',
        ]);

        $capturedArgsPath = storage_path('app/temp/captured_ytdlp_args_delay.txt');
        if (file_exists($capturedArgsPath)) {
            unlink($capturedArgsPath);
        }

        // Quoted heredoc: $@/$arg/$out_dir must reach bash untouched, so the captured-args
        // path is substituted afterwards rather than interpolated by PHP.
        $scriptBody = <<<'BASH'
#!/bin/bash
printf '%s\n' "$@" > "__CAPTURED_ARGS_PATH__"
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
BASH;
        $scriptBody = str_replace('__CAPTURED_ARGS_PATH__', $capturedArgsPath, $scriptBody);

        $mockYtDlp = storage_path('app/temp/mock_ytdlp_delay.sh');
        file_put_contents($mockYtDlp, $scriptBody);
        chmod($mockYtDlp, 0755);
        config(['services.ytdlp_path' => $mockYtDlp]);

        Artisan::call('videos:download');

        $video->refresh();
        $this->assertEquals('completed', $video->status);

        $this->assertFileExists($capturedArgsPath);
        $capturedArgs = explode("\n", trim(file_get_contents($capturedArgsPath)));

        $sleepRequestsIndex = array_search('--sleep-requests', $capturedArgs);
        $this->assertNotFalse($sleepRequestsIndex, 'yt-dlp was not called with --sleep-requests.');
        $this->assertSame('7', $capturedArgs[$sleepRequestsIndex + 1]);

        $sleepIntervalIndex = array_search('--sleep-interval', $capturedArgs);
        $this->assertNotFalse($sleepIntervalIndex, 'yt-dlp was not called with --sleep-interval.');
        $this->assertSame('7', $capturedArgs[$sleepIntervalIndex + 1]);

        unlink($mockYtDlp);
        unlink($capturedArgsPath);
    }

    public function test_downloader_omits_sleep_flags_when_delay_is_set_to_zero()
    {
        Setting::set('ytdlp_delay_seconds', '0');

        $channel = Channel::create([
            'youtube_id' => 'UC_test_chan',
            'name' => 'Space Channel',
            'url' => 'https://example.com/space',
        ]);

        $video = Video::create([
            'channel_id' => $channel->id,
            'youtube_id' => 'no_delay_vid',
            'title' => 'No Delay Video',
            'published_at' => now(),
            'status' => 'pending',
        ]);

        $capturedArgsPath = storage_path('app/temp/captured_ytdlp_args_no_delay.txt');
        if (file_exists($capturedArgsPath)) {
            unlink($capturedArgsPath);
        }

        $scriptBody = <<<'BASH'
#!/bin/bash
printf '%s\n' "$@" > "__CAPTURED_ARGS_PATH__"
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
BASH;
        $scriptBody = str_replace('__CAPTURED_ARGS_PATH__', $capturedArgsPath, $scriptBody);

        $mockYtDlp = storage_path('app/temp/mock_ytdlp_no_delay.sh');
        file_put_contents($mockYtDlp, $scriptBody);
        chmod($mockYtDlp, 0755);
        config(['services.ytdlp_path' => $mockYtDlp]);

        Artisan::call('videos:download');

        $video->refresh();
        $this->assertEquals('completed', $video->status);

        $this->assertFileExists($capturedArgsPath);
        $capturedArgs = file_get_contents($capturedArgsPath);

        $this->assertStringNotContainsString('--sleep-requests', $capturedArgs);
        $this->assertStringNotContainsString('--sleep-interval', $capturedArgs);

        unlink($mockYtDlp);
        unlink($capturedArgsPath);
    }

    public function test_downloader_drains_multiple_pending_videos_in_a_single_invocation()
    {
        // Regression test for the fix: a single videos:download invocation must keep
        // processing pending videos one after another instead of handling exactly one and
        // exiting, so a backlog isn't stuck waiting on the every-2-minutes schedule tick.
        $channel = Channel::create([
            'youtube_id' => 'UC_multi_chan',
            'name' => 'Multi Channel',
            'url' => 'https://example.com/multi',
        ]);

        $videoOne = Video::create([
            'channel_id' => $channel->id,
            'youtube_id' => 'multi_vid_1',
            'title' => 'Multi Video One',
            'published_at' => '2026-07-11 08:00:00',
            'status' => 'pending',
        ]);
        $videoTwo = Video::create([
            'channel_id' => $channel->id,
            'youtube_id' => 'multi_vid_2',
            'title' => 'Multi Video Two',
            'published_at' => '2026-07-11 09:00:00',
            'status' => 'pending',
        ]);
        $videoThree = Video::create([
            'channel_id' => $channel->id,
            'youtube_id' => 'multi_vid_3',
            'title' => 'Multi Video Three',
            'published_at' => '2026-07-11 10:00:00',
            'status' => 'pending',
        ]);

        $mockYtDlp = storage_path('app/temp/mock_ytdlp_multi.sh');
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

        try {
            Artisan::call('videos:download');

            $videoOne->refresh();
            $videoTwo->refresh();
            $videoThree->refresh();

            $this->assertEquals('completed', $videoOne->status);
            $this->assertEquals('completed', $videoTwo->status);
            $this->assertEquals('completed', $videoThree->status);

            $this->assertStringContainsString('Done. Processed 3 video(s) this run.', Artisan::output());
        } finally {
            unlink($mockYtDlp);
            $downloadsDir = Setting::getStoragePath();
            exec('rm -rf '.escapeshellarg($downloadsDir.'/Multi Channel'));
        }
    }

    public function test_downloader_sleeps_between_videos_but_not_before_the_first_or_after_the_last()
    {
        // --sleep-requests/--sleep-interval only throttle requests *within* a single yt-dlp
        // process; since the queue-draining loop fires a fresh yt-dlp process per video, the
        // actual pacing between videos has to be an explicit sleep in that loop, mirroring how
        // CheckChannelsForNewVideos sleeps between channels.
        Sleep::fake();
        Setting::set('ytdlp_delay_seconds', '3');

        $channel = Channel::create([
            'youtube_id' => 'UC_sleep_multi_chan',
            'name' => 'Sleep Multi Channel',
            'url' => 'https://example.com/sleepmulti',
        ]);

        Video::create(['channel_id' => $channel->id, 'youtube_id' => 'sleep_multi_1', 'title' => 'One', 'published_at' => '2026-07-11 08:00:00', 'status' => 'pending']);
        Video::create(['channel_id' => $channel->id, 'youtube_id' => 'sleep_multi_2', 'title' => 'Two', 'published_at' => '2026-07-11 09:00:00', 'status' => 'pending']);
        Video::create(['channel_id' => $channel->id, 'youtube_id' => 'sleep_multi_3', 'title' => 'Three', 'published_at' => '2026-07-11 10:00:00', 'status' => 'pending']);

        $mockYtDlp = storage_path('app/temp/mock_ytdlp_sleep_multi.sh');
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

        try {
            Artisan::call('videos:download');

            // 3 videos: a gap only between consecutive videos, none before the first or after
            // the last, so 2 total sleeps of the configured delay.
            Sleep::assertSequence([
                Sleep::for(3)->seconds(),
                Sleep::for(3)->seconds(),
            ]);
        } finally {
            unlink($mockYtDlp);
            $downloadsDir = Setting::getStoragePath();
            exec('rm -rf '.escapeshellarg($downloadsDir.'/Sleep Multi Channel'));
        }
    }
}
