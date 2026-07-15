<?php

namespace Tests\Feature;

use App\Jobs\CheckChannelForNewVideosJob;
use App\Models\Channel;
use App\Models\Setting;
use App\Models\User;
use App\Models\Video;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class CheckNewVideosTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Setting::set('ytdlp_delay_seconds', '0');
    }

    /**
     * Create a fake yt-dlp executable that answers all three calls app:check-channels makes:
     * the live_status precheck (--print-to-file), the flat-playlist listing (--flat-playlist
     * -j), and the full per-video extraction (-j against a single watch URL — replies with
     * just that video's JSON line, found by matching the "v=" id in the URL).
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

    public function test_check_new_videos_endpoint_queues_a_job_instead_of_blocking_the_request()
    {
        Queue::fake();

        $user = User::factory()->create();
        $channel = Channel::create([
            'youtube_id' => 'UC_check_new_queue_chan',
            'name' => 'Check New Queue Channel',
            'url' => 'https://example.com/checknewqueue',
        ]);

        $response = $this->actingAs($user)->postJson("/channels/{$channel->id}/check-new-videos");

        $response->assertStatus(200);
        $response->assertJson(['queued' => true]);

        Queue::assertPushed(CheckChannelForNewVideosJob::class, function (CheckChannelForNewVideosJob $job) use ($channel) {
            return $job->channel->is($channel);
        });
    }

    public function test_check_new_videos_job_really_calls_ytdlp_and_queues_new_videos()
    {
        // Run the queue synchronously (instead of faking it) so this test proves the job
        // has real effects end-to-end: controller -> queued job -> app:check-channels ->
        // yt-dlp (mocked) -> a new Video row, not just that dispatch() was called.
        config(['queue.default' => 'sync']);

        $user = User::factory()->create();
        $channel = Channel::create([
            'youtube_id' => 'UC_check_new_real_chan',
            'name' => 'Check New Real Channel',
            'url' => 'https://www.youtube.com/@check_new_real_channel',
            'cutoff_date' => '2020-01-01',
        ]);

        $mockYtDlp = $this->mockYtDlpWithVideos('check_new_real', [[
            'id' => 'check_new_real_vid',
            'title' => 'Check New Real Video',
            'upload_date' => '20260713',
            'was_live' => false,
            'media_type' => null,
        ]]);
        config(['services.ytdlp_path' => $mockYtDlp]);

        $this->assertDatabaseMissing('videos', ['youtube_id' => 'check_new_real_vid']);

        $response = $this->actingAs($user)->postJson("/channels/{$channel->id}/check-new-videos");

        $response->assertStatus(200);
        $this->assertDatabaseHas('videos', [
            'youtube_id' => 'check_new_real_vid',
            'channel_id' => $channel->id,
        ]);
        $this->assertNotNull(Video::where('youtube_id', 'check_new_real_vid')->first());

        unlink($mockYtDlp);
    }

    public function test_check_new_videos_job_has_a_timeout_generous_enough_for_the_configurable_ytdlp_delay()
    {
        // Regression guard: app:check-channels does a live-status precheck (90s) + a
        // flat-playlist listing (240s), then one full per-video extraction (240s) for each
        // of up to 10 newly-discovered videos, with ytdlp_delay_seconds' max allowed value
        // (120s, see Settings validation) slept between every one of those calls. Worst
        // case is 90 + 120 + 240 + 10*(120+240) = 4050s. The queue worker's default 60s
        // timeout — or an earlier, smaller job timeout sized for the old single-call-per-
        // video-batch design — would kill the job outright before that finishes, silently
        // dropping the check (see production incident: the job landed in failed_jobs with
        // a TimeoutExceededException).
        $channel = Channel::create([
            'youtube_id' => 'UC_timeout_chan',
            'name' => 'Timeout Channel',
            'url' => 'https://example.com/timeout',
        ]);

        $job = new CheckChannelForNewVideosJob($channel);

        $this->assertGreaterThanOrEqual(4050, $job->timeout);
    }

    public function test_check_new_videos_job_reports_a_stable_unique_id_keyed_by_channel()
    {
        // ShouldBeUnique, keyed by channel ID via uniqueId(), is what stops Laravel's queue
        // layer from ever dispatching two instances of this job for the *same* channel at
        // once (e.g. a repeat click of "Check for New Videos" while one is still queued).
        // Note this only protects this queued-job path — it can't see the *scheduled*
        // app:check-channels sweep, which doesn't go through this job at all; that race is
        // closed separately by the per-channel Cache::lock inside
        // CheckChannelsForNewVideos::handle().
        $channelA = Channel::create([
            'youtube_id' => 'UC_unique_id_chan_a',
            'name' => 'Unique ID Channel A',
            'url' => 'https://example.com/uniqueidchana',
        ]);
        $channelB = Channel::create([
            'youtube_id' => 'UC_unique_id_chan_b',
            'name' => 'Unique ID Channel B',
            'url' => 'https://example.com/uniqueidchanb',
        ]);

        $jobA = new CheckChannelForNewVideosJob($channelA);
        $jobAAgain = new CheckChannelForNewVideosJob($channelA);
        $jobB = new CheckChannelForNewVideosJob($channelB);

        $this->assertInstanceOf(ShouldBeUnique::class, $jobA);
        $this->assertSame((string) $channelA->id, $jobA->uniqueId());
        $this->assertSame($jobA->uniqueId(), $jobAAgain->uniqueId());
        $this->assertNotSame($jobA->uniqueId(), $jobB->uniqueId());
    }

    public function test_check_new_videos_endpoint_does_not_double_queue_the_job_for_the_same_channel()
    {
        Queue::fake();

        $user = User::factory()->create();
        $channel = Channel::create([
            'youtube_id' => 'UC_double_click_chan',
            'name' => 'Double Click Channel',
            'url' => 'https://example.com/doubleclick',
        ]);

        // Two rapid clicks of "Check for New Videos" on the same channel must not both land
        // in the queue: the job's ShouldBeUnique lock (keyed by channel ID) makes the second
        // dispatch() a silent no-op while the first is still pending.
        $this->actingAs($user)->postJson("/channels/{$channel->id}/check-new-videos");
        $this->actingAs($user)->postJson("/channels/{$channel->id}/check-new-videos");

        Queue::assertPushed(CheckChannelForNewVideosJob::class, 1);
    }
}
