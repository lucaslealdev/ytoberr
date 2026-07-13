<?php

namespace Tests\Feature;

use App\Jobs\CheckChannelForNewVideosJob;
use App\Models\Channel;
use App\Models\Setting;
use App\Models\User;
use App\Models\Video;
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
     * Create a fake yt-dlp executable that answers both calls app:check-channels makes:
     * the live_status precheck (--print-to-file) and the video listing (-j).
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

cat <<'VIDEOS'
__VIDEOS__
VIDEOS
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
        // Regression guard: app:check-channels sleeps up to ytdlp_delay_seconds' max allowed
        // value (120s, see Settings validation) between its two yt-dlp calls, plus real
        // network time for each call. The queue worker's default 60s timeout would kill the
        // job outright before that finishes, silently dropping the check (see production
        // incident: the job landed in failed_jobs with a TimeoutExceededException).
        $channel = Channel::create([
            'youtube_id' => 'UC_timeout_chan',
            'name' => 'Timeout Channel',
            'url' => 'https://example.com/timeout',
        ]);

        $job = new CheckChannelForNewVideosJob($channel);

        $this->assertGreaterThanOrEqual(300, $job->timeout);
    }
}
