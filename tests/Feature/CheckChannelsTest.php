<?php

namespace Tests\Feature;

use App\Models\Channel;
use App\Models\Setting;
use App\Models\Video;
use App\Models\Warning;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Sleep;
use Tests\TestCase;

class CheckChannelsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // yt-dlp is always mocked in this test class (see mockYtDlpWithVideos()); the
        // production safety delay between requests would only slow the suite down
        // without adding value.
        Setting::set('ytdlp_delay_seconds', '0');
    }

    /**
     * Create a fake yt-dlp executable that answers all three calls the check-channels command
     * makes: the live_status precheck (YtDlpWrapper's --print-to-file selective mode), the
     * flat-playlist listing (--flat-playlist -j, one JSON object per line), and the full
     * per-video extraction (-j against a single watch URL — replies with just that video's
     * JSON line, found by matching the "v=" id in the URL).
     *
     * @param  array<int, array<string, mixed>>  $videos
     */
    private function mockYtDlpWithVideos(string $name, array $videos): string
    {
        $mockYtDlp = storage_path("app/temp/mock_ytdlp_{$name}.sh");

        $jsonLines = collect($videos)
            ->map(fn (array $video) => json_encode($video))
            ->implode("\n");

        $script = <<<'BASH'
#!/bin/bash
if [[ "$*" == *"--print-to-file"* ]]; then
    args=("$@")
    for i in "${!args[@]}"; do
        if [[ "${args[$i]}" == "--print-to-file" ]]; then
            outfile="${args[$((i+2))]}"
            echo '{"live_status": null}' > "$outfile"
            exit 0
        fi
    done
fi

VIDEOS_JSON=$(cat <<'VIDEOSEOF'
__VIDEOS__
VIDEOSEOF
)

if [[ "$*" == *"--flat-playlist"* ]]; then
    echo "$VIDEOS_JSON"
    exit 0
fi

last_arg="${@: -1}"
video_id=$(echo "$last_arg" | grep -oP '(?<=v=)[^&]+')
echo "$VIDEOS_JSON" | grep -F "\"id\":\"$video_id\""
exit 0
BASH;

        file_put_contents($mockYtDlp, str_replace('__VIDEOS__', $jsonLines, $script));
        chmod($mockYtDlp, 0755);

        return $mockYtDlp;
    }

    /**
     * @param  array<int, string>  $shortIds
     * @param  array<int, string>  $regularIds
     * @return array<int, array<string, mixed>>
     */
    private function shortsChannelVideos(array $shortIds, array $regularIds): array
    {
        $videos = [];

        foreach ($shortIds as $id) {
            $videos[] = ['id' => $id, 'title' => "Short {$id}", 'upload_date' => '20230601', 'was_live' => false, 'media_type' => 'short'];
        }

        foreach ($regularIds as $id) {
            $videos[] = ['id' => $id, 'title' => "Video {$id}", 'upload_date' => '20230601', 'was_live' => false, 'media_type' => null];
        }

        return $videos;
    }

    public function test_check_channels_command_runs_successfully()
    {
        $channel = Channel::create([
            'youtube_id' => 'UC_placeholder_channel',
            'name' => 'Placeholder Channel',
            'url' => 'https://www.youtube.com/@placeholder_channel',
            'download_quality' => '720p',
        ]);

        $mockYtDlp = $this->mockYtDlpWithVideos('runs_successfully', []);
        config(['services.ytdlp_path' => $mockYtDlp]);

        $exitCode = Artisan::call('app:check-channels');

        $this->assertEquals(0, $exitCode, 'The app:check-channels command failed.');
        $this->assertStringContainsString('Checking channel: Placeholder Channel', Artisan::output());

        unlink($mockYtDlp);
    }

    public function test_check_channels_uses_the_ytdlp_timestamp_field_for_the_exact_publish_time()
    {
        // 'upload_date' is day-only (YYYYMMDD); the 'timestamp' field is the Unix epoch and
        // carries the real publish time, so it must win when both are present.
        $channel = Channel::create([
            'youtube_id' => 'UC_timestamp_chan',
            'name' => 'Timestamp Channel',
            'url' => 'https://www.youtube.com/@timestamp_channel',
            'download_quality' => '720p',
            'cutoff_date' => '2020-01-01',
        ]);

        $publishedAt = Carbon::create(2026, 7, 13, 15, 30, 45, 'UTC');

        $videos = [[
            'id' => 'timestamp_vid',
            'title' => 'Timestamp Video',
            'upload_date' => $publishedAt->format('Ymd'),
            'timestamp' => $publishedAt->timestamp,
            'was_live' => false,
            'media_type' => null,
        ]];

        $mockYtDlp = $this->mockYtDlpWithVideos('timestamp_channel', $videos);
        config(['services.ytdlp_path' => $mockYtDlp]);

        Artisan::call('app:check-channels', ['--channel' => $channel->id]);

        $video = Video::where('youtube_id', 'timestamp_vid')->firstOrFail();
        $this->assertEquals($publishedAt->toDateTimeString(), $video->published_at);

        unlink($mockYtDlp);
    }

    public function test_check_channels_captures_the_video_duration_from_ytdlp()
    {
        $channel = Channel::create([
            'youtube_id' => 'UC_duration_chan',
            'name' => 'Duration Channel',
            'url' => 'https://www.youtube.com/@duration_channel',
            'cutoff_date' => '2020-01-01',
        ]);

        $videos = [[
            'id' => 'duration_vid',
            'title' => 'Duration Video',
            'upload_date' => '20260713',
            'duration' => 754,
            'was_live' => false,
            'media_type' => null,
        ]];

        $mockYtDlp = $this->mockYtDlpWithVideos('duration_channel', $videos);
        config(['services.ytdlp_path' => $mockYtDlp]);

        Artisan::call('app:check-channels', ['--channel' => $channel->id]);

        $video = Video::where('youtube_id', 'duration_vid')->firstOrFail();
        $this->assertEquals(754, $video->duration);

        unlink($mockYtDlp);
    }

    public function test_check_channels_falls_back_to_midnight_when_ytdlp_omits_the_timestamp_field()
    {
        $channel = Channel::create([
            'youtube_id' => 'UC_no_timestamp_chan',
            'name' => 'No Timestamp Channel',
            'url' => 'https://www.youtube.com/@no_timestamp_channel',
            'download_quality' => '720p',
            'cutoff_date' => '2020-01-01',
        ]);

        $videos = [[
            'id' => 'no_timestamp_vid',
            'title' => 'No Timestamp Video',
            'upload_date' => '20260713',
            'was_live' => false,
            'media_type' => null,
        ]];

        $mockYtDlp = $this->mockYtDlpWithVideos('no_timestamp_channel', $videos);
        config(['services.ytdlp_path' => $mockYtDlp]);

        Artisan::call('app:check-channels', ['--channel' => $channel->id]);

        $video = Video::where('youtube_id', 'no_timestamp_vid')->firstOrFail();
        $this->assertEquals('2026-07-13 00:00:00', $video->published_at);

        unlink($mockYtDlp);
    }

    public function test_check_channels_command_skips_live_content()
    {
        $channel = Channel::create([
            'youtube_id' => 'UC_live_content_chan',
            'name' => 'Live Content Channel',
            'url' => 'https://www.youtube.com/@live_content_channel',
            'download_quality' => '720p',
            'cutoff_date' => '2020-01-01', // Set a date in the past so the regular videos in the test can be identified
        ]);

        $liveIds = ['Jo_DcOL5WfE', 'uv7uihW1vro', 'Z4__qS94pgc', 'ZhuAh83Hv2M', 'Uw6jgKabtf4', 'a4vQsYGJWI4', '1HkgZzYnQ7E'];
        $regularIds = ['Hn-GPYb6WB4', 'lLMGwlNc0xU', 'hn947xnfQ_4', 'D2ImaSpbyA8', 'X-T12_2uXCE', 'E-gxs1YJN6s', 'wLD6rwUIqn0', 'NkIo_bd65o4'];

        $videos = [];
        foreach ($liveIds as $id) {
            $videos[] = ['id' => $id, 'title' => "Live replay {$id}", 'upload_date' => '20230601', 'was_live' => true, 'media_type' => null];
        }
        foreach ($regularIds as $id) {
            $videos[] = ['id' => $id, 'title' => "Video {$id}", 'upload_date' => '20230601', 'was_live' => false, 'media_type' => null];
        }

        $mockYtDlp = $this->mockYtDlpWithVideos('live_content_channel', $videos);
        config(['services.ytdlp_path' => $mockYtDlp]);

        // Run the command
        Artisan::call('app:check-channels');

        $output = Artisan::output();

        // 1. Assert that the console output indicates videos that were live were skipped
        $this->assertStringContainsString('Originated from a live stream', $output, 'No recorded live was skipped or reported in the output.');

        // 2. Assert that recorded-live videos were skipped, and persisted as excluded (not
        // queued for download, and not re-checked on a future run) rather than left out of the
        // database entirely.
        foreach ($liveIds as $liveId) {
            $this->assertStringContainsString("Skipping video {$liveId}", $output);
            $this->assertDatabaseHas('videos', [
                'youtube_id' => $liveId,
                'status' => 'excluded',
                'prevent_download' => true,
            ]);
        }

        // 3. Assert that the channel's regular (non-live) videos WERE saved to the database
        foreach ($regularIds as $regularId) {
            $this->assertStringContainsString("New video found: {$regularId}", $output);
            $this->assertDatabaseHas('videos', ['youtube_id' => $regularId]);
        }

        unlink($mockYtDlp);
    }

    public function test_check_channels_command_skips_youtube_shorts()
    {
        $channel = Channel::create([
            'youtube_id' => 'UCLTWPE7XrHEe8m_xAmNbQ-Q',
            'name' => 'Shorts Channel',
            'url' => 'https://www.youtube.com/@shorts_channel',
            'download_quality' => '720p',
            'cutoff_date' => '2020-01-01',
        ]);

        $shortIds = ['gwgmZwB1adc', 'DE3LnuEJKB8', '9oBhMeK8Wo0', 'rgsRGgbDcYQ', '60fUrg1YiM4'];
        $regularIds = ['x4u2X4nqIN4', 'q-5yrOkczoU', '5ieN4SK8-Bs'];

        $mockYtDlp = $this->mockYtDlpWithVideos('shorts_channel_skip', $this->shortsChannelVideos($shortIds, $regularIds));
        config(['services.ytdlp_path' => $mockYtDlp]);

        Artisan::call('app:check-channels', ['--channel' => $channel->id]);

        $output = Artisan::output();

        $this->assertStringContainsString('YouTube Short', $output, 'No Short was skipped or reported in the output.');

        // Shorts must be skipped, and persisted as excluded (not queued for download, and not
        // re-checked on a future run) rather than left out of the database entirely.
        foreach ($shortIds as $shortId) {
            $this->assertStringContainsString("Skipping video {$shortId}: YouTube Short.", $output);
            $this->assertDatabaseHas('videos', [
                'youtube_id' => $shortId,
                'status' => 'excluded',
                'prevent_download' => true,
            ]);
        }

        // Regular (non-Shorts) videos from the same channel must still be saved normally.
        foreach ($regularIds as $regularId) {
            $this->assertStringContainsString("New video found: {$regularId}", $output);
        }

        unlink($mockYtDlp);
    }

    public function test_check_channels_command_includes_shorts_when_channel_opts_in()
    {
        $channel = Channel::create([
            'youtube_id' => 'UCLTWPE7XrHEe8m_xAmNbQ-Q',
            'name' => 'Shorts Channel Enabled',
            'url' => 'https://www.youtube.com/@shorts_channel',
            'download_quality' => '720p',
            'cutoff_date' => '2020-01-01',
            'download_shorts' => true,
        ]);

        // Same known Shorts as test_check_channels_command_skips_youtube_shorts,
        // but this time the channel opted in, so they must be queued instead of skipped.
        $shortIds = ['gwgmZwB1adc', 'DE3LnuEJKB8', '9oBhMeK8Wo0', 'rgsRGgbDcYQ', '60fUrg1YiM4'];
        $regularIds = ['x4u2X4nqIN4', 'q-5yrOkczoU', '5ieN4SK8-Bs'];

        $mockYtDlp = $this->mockYtDlpWithVideos('shorts_channel_include', $this->shortsChannelVideos($shortIds, $regularIds));
        config(['services.ytdlp_path' => $mockYtDlp]);

        Artisan::call('app:check-channels', ['--channel' => $channel->id]);

        $output = Artisan::output();

        foreach ($shortIds as $shortId) {
            $this->assertStringContainsString("New video found: {$shortId}", $output);
            $this->assertDatabaseHas('videos', ['youtube_id' => $shortId]);
        }

        $this->assertStringNotContainsString('YouTube Short', $output, 'A Short was skipped even with download_shorts enabled.');

        unlink($mockYtDlp);
    }

    public function test_check_channels_persists_pre_cutoff_videos_as_excluded_instead_of_re_checking_them_forever()
    {
        // Regression test: a candidate published before the channel's cut-off date was
        // previously never persisted, so it kept sitting in the last-10 uploads and paying for
        // a full yt-dlp extraction every single run (every 3 hours), forever.
        $channel = Channel::create([
            'youtube_id' => 'UC_pre_cutoff_chan',
            'name' => 'Pre Cutoff Channel',
            'url' => 'https://www.youtube.com/@pre_cutoff_channel',
            'download_quality' => '720p',
            'cutoff_date' => '2026-07-01',
        ]);

        $videos = [[
            'id' => 'old_vid',
            'title' => 'Old Video',
            'upload_date' => '20260601',
            'was_live' => false,
            'media_type' => null,
        ]];

        $mockYtDlp = $this->mockYtDlpWithVideos('pre_cutoff_channel', $videos);
        config(['services.ytdlp_path' => $mockYtDlp]);

        Artisan::call('app:check-channels', ['--channel' => $channel->id]);

        $output = Artisan::output();
        $this->assertStringContainsString('published before channel cut-off date', $output);
        $this->assertDatabaseHas('videos', [
            'youtube_id' => 'old_vid',
            'status' => 'excluded',
            'prevent_download' => true,
        ]);

        // A second run must not re-attempt the now-known video: it's excluded by the existence
        // check before the expensive per-video fetch ever happens again.
        Artisan::call('app:check-channels', ['--channel' => $channel->id]);
        $this->assertStringNotContainsString('old_vid', Artisan::output());

        unlink($mockYtDlp);
    }

    public function test_check_channels_logs_a_warning_when_a_channel_check_fails()
    {
        $channel = Channel::create([
            'youtube_id' => 'UC_check_fail_chan',
            'name' => 'Check Fail Channel',
            'url' => 'https://example.com/checkfail',
        ]);

        $mockYtDlp = storage_path('app/temp/mock_ytdlp_check_fail.sh');
        file_put_contents($mockYtDlp, '#!/bin/bash
echo "ERROR: General Connection Error"
exit 1
');
        chmod($mockYtDlp, 0755);
        config(['services.ytdlp_path' => $mockYtDlp]);

        Artisan::call('app:check-channels', ['--channel' => $channel->id]);

        $this->assertDatabaseHas('warnings', ['source' => 'channel_check_failed']);

        unlink($mockYtDlp);
    }

    public function test_check_channels_queries_the_videos_tab_explicitly_instead_of_the_bare_channel_url()
    {
        // Regression test: a bare channel handle URL (as stored on Channel::url) makes yt-dlp
        // enumerate every tab (Videos, Shorts, Live) as separate sub-playlists, each
        // independently capped by --playlist-items :10 — so without pinning to /videos, the
        // "last 10" candidate list can balloon past 10 and mix in unrelated Shorts/streams,
        // including ones far outside the actual upload recency order.
        $channel = Channel::create([
            'youtube_id' => 'UC_videos_tab_chan',
            'name' => 'Videos Tab Channel',
            'url' => 'https://www.youtube.com/@videos_tab_channel',
            'download_quality' => '720p',
        ]);

        $capturedUrlFile = storage_path('app/temp/captured_flat_playlist_url.txt');
        if (file_exists($capturedUrlFile)) {
            unlink($capturedUrlFile);
        }

        $mockYtDlp = storage_path('app/temp/mock_ytdlp_videos_tab.sh');
        $script = <<<'BASH'
#!/bin/bash
if [[ "$*" == *"--print-to-file"* ]]; then
    args=("$@")
    for i in "${!args[@]}"; do
        if [[ "${args[$i]}" == "--print-to-file" ]]; then
            outfile="${args[$((i+2))]}"
            echo '{"live_status": null}' > "$outfile"
            exit 0
        fi
    done
fi

if [[ "$*" == *"--flat-playlist"* ]]; then
    last_arg="${@: -1}"
    echo "$last_arg" >> __CAPTURE_FILE__
    exit 0
fi

exit 0
BASH;

        file_put_contents($mockYtDlp, str_replace('__CAPTURE_FILE__', $capturedUrlFile, $script));
        chmod($mockYtDlp, 0755);
        config(['services.ytdlp_path' => $mockYtDlp]);

        Artisan::call('app:check-channels', ['--channel' => $channel->id]);

        $this->assertFileExists($capturedUrlFile);
        $this->assertEquals('https://www.youtube.com/@videos_tab_channel/videos', trim(file_get_contents($capturedUrlFile)));

        unlink($mockYtDlp);
        unlink($capturedUrlFile);
    }

    public function test_check_channels_marks_permanently_unavailable_videos_as_failed_after_three_consecutive_failures()
    {
        // Regression test: previously, a video whose full metadata fetch failed was never
        // persisted, so it kept reappearing as a "new" candidate on every run (every 3 hours,
        // forever) and kept generating identical video_check_failed warnings. It must now stop
        // retrying — but only once it has genuinely failed 3 checks in a row (see the sibling
        // test below for why a single failure must NOT be enough).
        $channel = Channel::create([
            'youtube_id' => 'UC_unavailable_chan',
            'name' => 'Unavailable Video Channel',
            'url' => 'https://www.youtube.com/@unavailable_channel',
            'download_quality' => '720p',
        ]);

        $mockYtDlp = storage_path('app/temp/mock_ytdlp_unavailable.sh');
        file_put_contents($mockYtDlp, <<<'BASH'
#!/bin/bash
if [[ "$*" == *"--print-to-file"* ]]; then
    args=("$@")
    for i in "${!args[@]}"; do
        if [[ "${args[$i]}" == "--print-to-file" ]]; then
            outfile="${args[$((i+2))]}"
            echo '{"live_status": null}' > "$outfile"
            exit 0
        fi
    done
fi

if [[ "$*" == *"--flat-playlist"* ]]; then
    echo '{"id":"i__XFWFch_s","title":"Old Video"}'
    exit 0
fi

echo "ERROR: [youtube] i__XFWFch_s: Video unavailable. This video is not available"
exit 1
BASH);
        chmod($mockYtDlp, 0755);
        config(['services.ytdlp_path' => $mockYtDlp]);

        // Runs 1 and 2: still just transient-looking failures, so no video row yet.
        Artisan::call('app:check-channels', ['--channel' => $channel->id]);
        Artisan::call('app:check-channels', ['--channel' => $channel->id]);
        $this->assertNull(Video::where('youtube_id', 'i__XFWFch_s')->first());
        $this->assertEquals(2, Warning::where('source', 'video_check_failed')->count());

        // Run 3: the same video has now failed 3 times in a row — accept it's really gone.
        Artisan::call('app:check-channels', ['--channel' => $channel->id]);

        $video = Video::where('youtube_id', 'i__XFWFch_s')->first();
        $this->assertNotNull($video, 'A failed placeholder video row should have been created after 3 consecutive failures.');
        $this->assertEquals('failed', $video->status);
        $this->assertTrue((bool) $video->prevent_download);
        $this->assertNotNull($video->unavailable_reason);

        $this->assertDatabaseHas('warnings', [
            'source' => 'video_check_failed',
            'video_id' => $video->id,
        ]);

        // Running the check again must not re-attempt the now-known video: it's excluded by
        // the existence check before the expensive per-video fetch ever happens again.
        Artisan::call('app:check-channels', ['--channel' => $channel->id]);
        $this->assertEquals(3, Warning::where('source', 'video_check_failed')->count());

        unlink($mockYtDlp);
    }

    public function test_check_channels_skips_members_only_videos_silently_and_keeps_retrying_indefinitely()
    {
        // Regression test: a members-only restriction is not necessarily permanent (the
        // channel's uploader could grant access later), so unlike a genuinely unavailable
        // video it must never be persisted as a known/excluded row, must never generate a
        // Warning, and must keep being reconsidered on every future channel check — not just
        // for 2 checks before being permanently blacklisted like detectUnavailableReason()'s
        // other reasons.
        $channel = Channel::create([
            'youtube_id' => 'UC_members_only_chan',
            'name' => 'Members Only Channel',
            'url' => 'https://www.youtube.com/@members_only_channel',
            'download_quality' => '720p',
        ]);

        $mockYtDlp = storage_path('app/temp/mock_ytdlp_members_only_check.sh');
        file_put_contents($mockYtDlp, <<<'BASH'
#!/bin/bash
if [[ "$*" == *"--print-to-file"* ]]; then
    args=("$@")
    for i in "${!args[@]}"; do
        if [[ "${args[$i]}" == "--print-to-file" ]]; then
            outfile="${args[$((i+2))]}"
            echo '{"live_status": null}' > "$outfile"
            exit 0
        fi
    done
fi

if [[ "$*" == *"--flat-playlist"* ]]; then
    echo '{"id":"ezeZ-qoxaXA","title":"Members Only Video"}'
    exit 0
fi

echo "ERROR: [youtube] ezeZ-qoxaXA: This video is available to this channel's members on level: miserável (or any higher level). Join this channel to get access to members-only content and other exclusive perks."
exit 1
BASH);
        chmod($mockYtDlp, 0755);
        config(['services.ytdlp_path' => $mockYtDlp]);

        // Run it several times (well past the 3-strikes threshold used for other unavailable
        // reasons) — it must never get persisted or warned about, no matter how many times.
        for ($i = 0; $i < 4; $i++) {
            Artisan::call('app:check-channels', ['--channel' => $channel->id]);
        }

        $this->assertNull(Video::where('youtube_id', 'ezeZ-qoxaXA')->first());
        $this->assertSame(0, Warning::where('source', 'video_check_failed')->count());
        $this->assertStringContainsString('Skipping video ezeZ-qoxaXA: members-only content', Artisan::output());

        unlink($mockYtDlp);
    }

    public function test_check_channels_skips_upcoming_premieres_silently_and_keeps_retrying_indefinitely()
    {
        // Regression test: a scheduled premiere that hasn't gone live yet ("Premieres in 43
        // minutes") isn't a real failure — yt-dlp simply can't extract formats until it airs —
        // so like the members-only case, it must never be persisted as a known/excluded row,
        // must never generate a Warning, and must keep being reconsidered on every future
        // channel check.
        $channel = Channel::create([
            'youtube_id' => 'UC_premiere_chan',
            'name' => 'Premiere Channel',
            'url' => 'https://www.youtube.com/@premiere_channel',
            'download_quality' => '720p',
        ]);

        $mockYtDlp = storage_path('app/temp/mock_ytdlp_premiere_check.sh');
        file_put_contents($mockYtDlp, <<<'BASH'
#!/bin/bash
if [[ "$*" == *"--print-to-file"* ]]; then
    args=("$@")
    for i in "${!args[@]}"; do
        if [[ "${args[$i]}" == "--print-to-file" ]]; then
            outfile="${args[$((i+2))]}"
            echo '{"live_status": null}' > "$outfile"
            exit 0
        fi
    done
fi

if [[ "$*" == *"--flat-playlist"* ]]; then
    echo '{"id":"upcoming_premiere_vid","title":"Premiere Video"}'
    exit 0
fi

echo "ERROR: [youtube] upcoming_premiere_vid: Premieres in 43 minutes"
exit 1
BASH);
        chmod($mockYtDlp, 0755);
        config(['services.ytdlp_path' => $mockYtDlp]);

        // Run it several times (well past the 3-strikes threshold used for other unavailable
        // reasons) — it must never get persisted or warned about, no matter how many times.
        for ($i = 0; $i < 4; $i++) {
            Artisan::call('app:check-channels', ['--channel' => $channel->id]);
        }

        $this->assertNull(Video::where('youtube_id', 'upcoming_premiere_vid')->first());
        $this->assertSame(0, Warning::where('source', 'video_check_failed')->count());
        $this->assertStringContainsString('Skipping video upcoming_premiere_vid: premieres soon', Artisan::output());

        unlink($mockYtDlp);
    }

    public function test_check_channels_never_re_adds_a_manually_blacklisted_video()
    {
        // Regression test for VideoController::destroy()'s "don't download this video again"
        // option: it keeps the row around (status=deleted) specifically so this "already
        // known" check treats it as such and never re-queues it, even though it still shows
        // up in the channel's last-10-uploads listing.
        $channel = Channel::create([
            'youtube_id' => 'UC_blacklisted_chan',
            'name' => 'Blacklisted Video Channel',
            'url' => 'https://www.youtube.com/@blacklisted_video_channel',
            'download_quality' => '720p',
        ]);

        Video::create([
            'channel_id' => $channel->id,
            'youtube_id' => 'blacklisted_vid',
            'title' => 'Blacklisted Video',
            'published_at' => now(),
            'status' => 'deleted',
            'prevent_download' => true,
            'unavailable_reason' => 'Manually deleted',
        ]);

        $videos = [[
            'id' => 'blacklisted_vid',
            'title' => 'Blacklisted Video',
            'upload_date' => now()->format('Ymd'),
            'was_live' => false,
            'media_type' => null,
        ]];

        $mockYtDlp = $this->mockYtDlpWithVideos('blacklisted_video', $videos);
        config(['services.ytdlp_path' => $mockYtDlp]);

        Artisan::call('app:check-channels', ['--channel' => $channel->id]);

        $this->assertSame(1, Video::where('youtube_id', 'blacklisted_vid')->count());
        $this->assertSame('deleted', Video::where('youtube_id', 'blacklisted_vid')->first()->status);

        unlink($mockYtDlp);
    }

    public function test_check_channels_does_not_permanently_blacklist_a_video_after_a_single_transient_failure()
    {
        // Regression test: yt-dlp/YouTube's bot-detection (or a JS-runtime hiccup) can produce a
        // one-off "Video unavailable" response for a video that is actually fine — observed in
        // practice against a real video that came back with full metadata on the very next
        // attempt. A single failed check must not permanently exclude it from ever being queued.
        $channel = Channel::create([
            'youtube_id' => 'UC_flaky_chan',
            'name' => 'Flaky Video Channel',
            'url' => 'https://www.youtube.com/@flaky_channel',
            'download_quality' => '720p',
            'cutoff_date' => '2020-01-01',
        ]);

        $stateFile = storage_path('app/temp/flaky_video_attempts.txt');
        if (file_exists($stateFile)) {
            unlink($stateFile);
        }

        $mockYtDlp = storage_path('app/temp/mock_ytdlp_flaky.sh');
        $script = <<<'BASH'
#!/bin/bash
if [[ "$*" == *"--print-to-file"* ]]; then
    args=("$@")
    for i in "${!args[@]}"; do
        if [[ "${args[$i]}" == "--print-to-file" ]]; then
            outfile="${args[$((i+2))]}"
            echo '{"live_status": null}' > "$outfile"
            exit 0
        fi
    done
fi

if [[ "$*" == *"--flat-playlist"* ]]; then
    echo '{"id":"flaky_vid","title":"Flaky Video"}'
    exit 0
fi

echo "x" >> __STATE_FILE__
attempts=$(wc -l < __STATE_FILE__)
if [[ "$attempts" -eq 1 ]]; then
    echo "ERROR: [youtube] flaky_vid: Video unavailable. This video is not available"
    exit 1
fi
echo '{"id":"flaky_vid","title":"Flaky Video","upload_date":"20260701","was_live":false,"media_type":null}'
exit 0
BASH;

        file_put_contents($mockYtDlp, str_replace('__STATE_FILE__', $stateFile, $script));
        chmod($mockYtDlp, 0755);
        config(['services.ytdlp_path' => $mockYtDlp]);

        // Run 1: fails once (transient).
        Artisan::call('app:check-channels', ['--channel' => $channel->id]);
        $this->assertNull(Video::where('youtube_id', 'flaky_vid')->first(), 'A single failure must not create a blacklisted placeholder.');

        // Run 2: succeeds — the video must be queued normally, not treated as unavailable.
        Artisan::call('app:check-channels', ['--channel' => $channel->id]);
        $video = Video::where('youtube_id', 'flaky_vid')->first();
        $this->assertNotNull($video);
        $this->assertEquals('pending', $video->status);
        $this->assertFalse((bool) $video->prevent_download);
        $this->assertNull($video->unavailable_reason);

        unlink($mockYtDlp);
        unlink($stateFile);
    }

    public function test_check_channels_sleeps_between_the_two_ytdlp_calls_for_the_same_channel()
    {
        // Each channel makes two separate yt-dlp processes (live_status precheck, then the
        // playlist listing). --sleep-requests only throttles requests *within* one of those
        // processes, so the gap between the two calls has to be an explicit sleep in the loop.
        Sleep::fake();
        Setting::set('ytdlp_delay_seconds', '3');

        Channel::create(['youtube_id' => 'UC_sleep_single_chan', 'name' => 'Sleep Single Channel', 'url' => 'https://example.com/sleepsingle']);

        $mockYtDlp = storage_path('app/temp/mock_ytdlp_sleep_single.sh');
        file_put_contents($mockYtDlp, "#!/bin/bash\nexit 0\n");
        chmod($mockYtDlp, 0755);
        config(['services.ytdlp_path' => $mockYtDlp]);

        Artisan::call('app:check-channels');

        // A single channel has no "next channel" to sleep before, but must still sleep once
        // between its own two yt-dlp calls.
        Sleep::assertSequence([
            Sleep::for(3)->seconds(),
        ]);

        unlink($mockYtDlp);
    }

    public function test_check_channels_sleeps_between_channels_but_not_before_the_first_or_after_the_last()
    {
        // --sleep-requests only throttles requests *within* a single yt-dlp process. With
        // multiple channels, this command fires a fresh yt-dlp process per channel, so the
        // real protection against hammering YouTube has to be an actual sleep between
        // channels in the PHP loop itself — that's what this test verifies.
        Sleep::fake();
        Setting::set('ytdlp_delay_seconds', '3');

        Channel::create(['youtube_id' => 'UC_sleep_test_a', 'name' => 'Sleep Test Channel A', 'url' => 'https://example.com/sleeptesta']);
        Channel::create(['youtube_id' => 'UC_sleep_test_b', 'name' => 'Sleep Test Channel B', 'url' => 'https://example.com/sleeptestb']);
        Channel::create(['youtube_id' => 'UC_sleep_test_c', 'name' => 'Sleep Test Channel C', 'url' => 'https://example.com/sleeptestc']);

        $mockYtDlp = storage_path('app/temp/mock_ytdlp_sleep_test.sh');
        file_put_contents($mockYtDlp, "#!/bin/bash\nexit 0\n");
        chmod($mockYtDlp, 0755);
        config(['services.ytdlp_path' => $mockYtDlp]);

        Artisan::call('app:check-channels');

        // 3 channels: 1 within-channel sleep each (3) + 1 between-channel gap for each of the
        // 2 transitions (2) = 5 total.
        Sleep::assertSleptTimes(5);

        unlink($mockYtDlp);
    }

    public function test_check_channels_survives_a_video_appearing_twice_in_the_same_batch()
    {
        // Simulates the race this command is now hardened against: the same youtube_id being
        // inserted twice in quick succession. The per-channel Cache::lock (see the sibling
        // test below) closes the race between this command and a manually-queued job for the
        // same channel, but it can't stop a video appearing twice within a single batch (as
        // reproduced deterministically here, no real concurrency needed) or any other
        // genuinely unforeseen race — so the second Video::create() must hit the unique
        // constraint and be caught, not crash the rest of the channel's (or run's) processing.
        $channel = Channel::create([
            'youtube_id' => 'UC_dup_insert_chan',
            'name' => 'Duplicate Insert Channel',
            'url' => 'https://www.youtube.com/@dup_insert_channel',
            'cutoff_date' => '2020-01-01',
        ]);

        $video = ['id' => 'dup_insert_vid', 'title' => 'Duplicate Insert Video', 'upload_date' => '20260713', 'was_live' => false, 'media_type' => null];
        $mockYtDlp = $this->mockYtDlpWithVideos('dup_insert_channel', [$video, $video]);
        config(['services.ytdlp_path' => $mockYtDlp]);

        $exitCode = Artisan::call('app:check-channels', ['--channel' => $channel->id]);

        $this->assertEquals(0, $exitCode, 'The app:check-channels command should not crash on a duplicate insert.');
        $this->assertEquals(1, Video::where('youtube_id', 'dup_insert_vid')->count());
        $this->assertDatabaseHas('warnings', ['source' => 'video_duplicate_insert_skipped']);

        unlink($mockYtDlp);
    }

    public function test_check_channels_skips_a_channel_not_yet_due_for_its_own_interval()
    {
        $channel = Channel::create([
            'youtube_id' => 'UC_not_due_chan',
            'name' => 'Not Due Channel',
            'url' => 'https://www.youtube.com/@not_due_channel',
            'check_interval_hours' => 6,
            'last_checked_at' => now()->subHours(2), // Checked 2h ago, interval is 6h: not due yet.
        ]);

        $videos = [['id' => 'not_due_vid', 'title' => 'Not Due Video', 'upload_date' => '20260713', 'was_live' => false, 'media_type' => null]];
        $mockYtDlp = $this->mockYtDlpWithVideos('not_due_channel', $videos);
        config(['services.ytdlp_path' => $mockYtDlp]);

        // The global sweep (no --channel) must respect the per-channel interval.
        Artisan::call('app:check-channels');

        $this->assertStringNotContainsString('Checking channel: Not Due Channel', Artisan::output());
        $this->assertDatabaseMissing('videos', ['youtube_id' => 'not_due_vid']);
        // A skipped channel's last_checked_at must be untouched, not bumped to "now".
        $this->assertEquals($channel->last_checked_at->timestamp, $channel->fresh()->last_checked_at->timestamp);

        unlink($mockYtDlp);
    }

    public function test_check_channels_checks_a_channel_once_its_own_interval_has_elapsed()
    {
        $channel = Channel::create([
            'youtube_id' => 'UC_now_due_chan',
            'name' => 'Now Due Channel',
            'url' => 'https://www.youtube.com/@now_due_channel',
            'cutoff_date' => '2020-01-01',
            'check_interval_hours' => 1,
            'last_checked_at' => now()->subHours(2), // Checked 2h ago, interval is 1h: due.
        ]);

        $videos = [['id' => 'now_due_vid', 'title' => 'Now Due Video', 'upload_date' => '20260713', 'was_live' => false, 'media_type' => null]];
        $mockYtDlp = $this->mockYtDlpWithVideos('now_due_channel', $videos);
        config(['services.ytdlp_path' => $mockYtDlp]);

        Artisan::call('app:check-channels');

        $this->assertStringContainsString('Checking channel: Now Due Channel', Artisan::output());
        $this->assertDatabaseHas('videos', ['youtube_id' => 'now_due_vid']);
        $this->assertTrue($channel->fresh()->last_checked_at->gt(now()->subMinute()));

        unlink($mockYtDlp);
    }

    public function test_check_channels_uses_the_default_interval_when_a_channel_has_not_set_one()
    {
        $channel = Channel::create([
            'youtube_id' => 'UC_default_interval_chan',
            'name' => 'Default Interval Channel',
            'url' => 'https://www.youtube.com/@default_interval_channel',
            // check_interval_hours left null: falls back to Channel::DEFAULT_CHECK_INTERVAL_HOURS (3h).
            'last_checked_at' => now()->subHours(1),
        ]);

        $mockYtDlp = $this->mockYtDlpWithVideos('default_interval_channel', []);
        config(['services.ytdlp_path' => $mockYtDlp]);

        // Checked 1h ago, default interval is 3h: not due yet.
        Artisan::call('app:check-channels');
        $this->assertStringNotContainsString('Checking channel: Default Interval Channel', Artisan::output());

        // Move the clock forward past the 3h default and confirm it's picked up on the next sweep.
        $channel->update(['last_checked_at' => now()->subHours(4)]);
        Artisan::call('app:check-channels');
        $this->assertStringContainsString('Checking channel: Default Interval Channel', Artisan::output());

        unlink($mockYtDlp);
    }

    public function test_check_channels_ignores_the_interval_when_explicitly_targeted_via_the_channel_option()
    {
        // The "Check for New Videos" button (CheckChannelForNewVideosJob) always passes
        // --channel for the clicked channel — that manual, explicit request must run
        // immediately regardless of how recently it was last auto-checked.
        $channel = Channel::create([
            'youtube_id' => 'UC_targeted_bypass_chan',
            'name' => 'Targeted Bypass Channel',
            'url' => 'https://www.youtube.com/@targeted_bypass_channel',
            'cutoff_date' => '2020-01-01',
            'check_interval_hours' => 6,
            'last_checked_at' => now(), // Just checked: would never be due on a global sweep.
        ]);

        $videos = [['id' => 'targeted_bypass_vid', 'title' => 'Targeted Bypass Video', 'upload_date' => '20260713', 'was_live' => false, 'media_type' => null]];
        $mockYtDlp = $this->mockYtDlpWithVideos('targeted_bypass_channel', $videos);
        config(['services.ytdlp_path' => $mockYtDlp]);

        Artisan::call('app:check-channels', ['--channel' => $channel->id]);

        $this->assertStringContainsString('Checking channel: Targeted Bypass Channel', Artisan::output());
        $this->assertDatabaseHas('videos', ['youtube_id' => 'targeted_bypass_vid']);

        unlink($mockYtDlp);
    }

    public function test_check_channels_skips_a_channel_already_locked_by_a_concurrent_check()
    {
        // Simulates a manually-queued CheckChannelForNewVideosJob already running
        // app:check-channels --channel=X for this channel (holding this same cache lock)
        // at the moment the scheduled sweep reaches it. ShouldBeUnique on that job only
        // stops two *queued* jobs for the channel from overlapping — it has no visibility
        // into this command's own synchronous run, so this per-channel lock is what actually
        // closes that race: the scheduled run must skip the channel rather than block on/
        // race with whichever process already holds the lock.
        $channel = Channel::create([
            'youtube_id' => 'UC_locked_chan',
            'name' => 'Locked Channel',
            'url' => 'https://www.youtube.com/@locked_channel',
            'cutoff_date' => '2020-01-01',
        ]);

        $videos = [['id' => 'locked_vid', 'title' => 'Locked Video', 'upload_date' => '20260713', 'was_live' => false, 'media_type' => null]];
        $mockYtDlp = $this->mockYtDlpWithVideos('locked_channel', $videos);
        config(['services.ytdlp_path' => $mockYtDlp]);

        $lock = Cache::lock("check-channel-videos:{$channel->id}", 60);
        $this->assertTrue($lock->get());

        Artisan::call('app:check-channels', ['--channel' => $channel->id]);

        $this->assertStringContainsString('already in progress', Artisan::output());
        $this->assertDatabaseMissing('videos', ['youtube_id' => 'locked_vid']);

        $lock->release();

        unlink($mockYtDlp);
    }
}
