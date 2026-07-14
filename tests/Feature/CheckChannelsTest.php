<?php

namespace Tests\Feature;

use App\Models\Channel;
use App\Models\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
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
     * Create a fake yt-dlp executable that answers both calls the check-channels command
     * makes: the live_status precheck (YtDlpWrapper's --print-to-file selective mode) and
     * the video listing (-j, one JSON object per line on stdout).
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

        $this->assertEquals(0, $exitCode, 'O comando app:check-channels falhou.');
        $this->assertStringContainsString('Checking channel: Placeholder Channel', Artisan::output());

        unlink($mockYtDlp);
    }

    public function test_check_channels_command_skips_live_content_for_rbiana()
    {
        $channel = Channel::create([
            'youtube_id' => 'UCDfRoZgAMaGCnUyl5n2yECw',
            'name' => 'Rbiana',
            'url' => 'https://www.youtube.com/@rbiana',
            'download_quality' => '720p',
            'cutoff_date' => '2020-01-01', // Define uma data no passado para permitir identificar os vídeos normais no teste
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

        $mockYtDlp = $this->mockYtDlpWithVideos('rbiana', $videos);
        config(['services.ytdlp_path' => $mockYtDlp]);

        // Executa o comando
        Artisan::call('app:check-channels');

        $output = Artisan::output();

        // 1. Assert que a saída do console indica que vídeos que foram live foram ignorados
        $this->assertStringContainsString('Originated from a live stream', $output, 'Nenhuma live gravada foi pulada ou reportada no output.');

        // 2. Assert que vídeos de lives gravadas foram pulados e NÃO cadastrados no banco
        foreach ($liveIds as $liveId) {
            $this->assertStringContainsString("Skipping video {$liveId}", $output);
            $this->assertDatabaseMissing('videos', [
                'youtube_id' => $liveId,
            ]);
        }

        // 3. Assert que vídeos comuns do canal que não foram live FORAM cadastrados no banco
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

        $this->assertStringContainsString('YouTube Short', $output, 'Nenhum Short foi pulado ou reportado no output.');

        // Shorts devem ser pulados e não cadastrados no banco.
        foreach ($shortIds as $shortId) {
            $this->assertStringContainsString("Skipping video {$shortId}: YouTube Short.", $output);
            $this->assertDatabaseMissing('videos', [
                'youtube_id' => $shortId,
            ]);
        }

        // Vídeos regulares (não Shorts) do mesmo canal devem continuar sendo cadastrados normalmente.
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

        $this->assertStringNotContainsString('YouTube Short', $output, 'Um Short foi pulado mesmo com download_shorts habilitado.');

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
}
