<?php

namespace Tests\Feature;

use App\Models\Channel;
use App\Models\Setting;
use App\Models\Video;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class BackfillPublishedAtTimeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Setting::set('ytdlp_delay_seconds', '0');
    }

    /**
     * Create a fake yt-dlp executable that answers YtDlpWrapper's selective
     * --print-to-file metadata mode by writing the given metadata to the requested file.
     *
     * @param  array<string, mixed>  $metadata
     */
    private function mockYtDlpMetadata(string $name, array $metadata): string
    {
        $mockYtDlp = storage_path("app/temp/mock_ytdlp_{$name}.sh");

        $json = json_encode($metadata);

        $script = <<<'BASH'
#!/bin/bash
args=("$@")
for i in "${!args[@]}"; do
    if [[ "${args[$i]}" == "--print-to-file" ]]; then
        outfile="${args[$((i+2))]}"
        cat <<'JSON' > "$outfile"
__METADATA__
JSON
        exit 0
    fi
done
exit 0
BASH;

        file_put_contents($mockYtDlp, str_replace('__METADATA__', $json, $script));
        chmod($mockYtDlp, 0755);

        return $mockYtDlp;
    }

    public function test_backfill_updates_published_at_with_the_real_time_from_ytdlp_timestamp()
    {
        $channel = Channel::create([
            'youtube_id' => 'UC_backfill_time_chan',
            'name' => 'Backfill Time Channel',
            'url' => 'https://example.com/backfilltime',
        ]);

        $video = Video::create([
            'channel_id' => $channel->id,
            'youtube_id' => 'backfill_time_vid',
            'title' => 'Backfill Time Video',
            'published_at' => '2026-07-13 00:00:00',
            'status' => 'completed',
        ]);

        $realPublishedAt = Carbon::create(2026, 7, 13, 21, 0, 45, 'UTC');

        $mockYtDlp = $this->mockYtDlpMetadata('backfill_time', ['timestamp' => $realPublishedAt->timestamp]);
        config(['services.ytdlp_path' => $mockYtDlp]);

        Artisan::call('operations:process', ['--sync' => true]);

        $video->refresh();
        $this->assertEquals($realPublishedAt->toDateTimeString(), $video->published_at);

        unlink($mockYtDlp);
    }

    public function test_backfill_skips_videos_that_already_have_a_non_midnight_published_at()
    {
        $channel = Channel::create([
            'youtube_id' => 'UC_backfill_skip_chan',
            'name' => 'Backfill Skip Channel',
            'url' => 'https://example.com/backfillskip',
        ]);

        $video = Video::create([
            'channel_id' => $channel->id,
            'youtube_id' => 'backfill_skip_vid',
            'title' => 'Backfill Skip Video',
            'published_at' => '2026-07-13 14:22:10',
            'status' => 'completed',
        ]);

        // No mock yt-dlp configured: if the operation queried this video, the real
        // configured binary would run and the command would fail/hang, so leaving no
        // mock in place doubles as proof the video was never looked up.
        Artisan::call('operations:process', ['--sync' => true]);

        $video->refresh();
        $this->assertEquals('2026-07-13 14:22:10', $video->published_at);
    }

    public function test_backfill_leaves_published_at_unchanged_when_ytdlp_has_no_timestamp()
    {
        Log::spy();

        $channel = Channel::create([
            'youtube_id' => 'UC_backfill_missing_chan',
            'name' => 'Backfill Missing Channel',
            'url' => 'https://example.com/backfillmissing',
        ]);

        $video = Video::create([
            'channel_id' => $channel->id,
            'youtube_id' => 'backfill_missing_vid',
            'title' => 'Backfill Missing Video',
            'published_at' => '2026-07-13 00:00:00',
            'status' => 'completed',
        ]);

        $mockYtDlp = $this->mockYtDlpMetadata('backfill_missing', ['timestamp' => null]);
        config(['services.ytdlp_path' => $mockYtDlp]);

        Artisan::call('operations:process', ['--sync' => true]);

        $video->refresh();
        $this->assertEquals('2026-07-13 00:00:00', $video->published_at);
        Log::shouldHaveReceived('warning')->once();

        unlink($mockYtDlp);
    }
}
