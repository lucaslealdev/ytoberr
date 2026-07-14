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

        // Most of these tests hit the real yt-dlp binary against real YouTube channels;
        // the production safety delay between requests would only slow the suite down
        // without adding value.
        Setting::set('ytdlp_delay_seconds', '0');
    }

    public function test_check_channels_command_runs_successfully()
    {
        $channel = Channel::create([
            'youtube_id' => 'UCexaxaj4QzEirjgmPCBdhSg',
            'name' => 'Jiraiya',
            'url' => 'https://www.youtube.com/@jiranha',
            'download_quality' => '720p',
        ]);

        $exitCode = Artisan::call('app:check-channels');

        $this->assertEquals(0, $exitCode, 'O comando app:check-channels falhou.');
        $this->assertStringContainsString('Checking channel: Jiraiya', Artisan::output());
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

        // Executa o comando
        Artisan::call('app:check-channels');

        $output = Artisan::output();

        // 1. Assert que a saída do console indica que vídeos que foram live foram ignorados
        $this->assertStringContainsString('Originated from a live stream', $output, 'Nenhuma live gravada foi pulada ou reportada no output.');

        // 2. Assert que vídeos de lives gravadas NÃO foram cadastrados no banco
        // IDs conhecidos de lives gravadas do canal @rbiana (ex: Jo_DcOL5WfE, uv7uihW1vro, Z4__qS94pgc)
        $liveIds = ['Jo_DcOL5WfE', 'uv7uihW1vro', 'Z4__qS94pgc', 'ZhuAh83Hv2M', 'Uw6jgKabtf4', 'a4vQsYGJWI4', '1HkgZzYnQ7E'];

        foreach ($liveIds as $liveId) {
            // Se o ID foi processado no output, garantimos que foi pulado e não inserido
            if (str_contains($output, "Skipping video {$liveId}")) {
                $this->assertDatabaseMissing('videos', [
                    'youtube_id' => $liveId,
                ]);
            }
        }

        // 3. Assert que vídeos comuns do canal que não foram live FORAM cadastrados no banco
        // IDs conhecidos de vídeos comuns do canal @rbiana (ex: Hn-GPYb6WB4, lLMGwlNc0xU, hn947xnfQ_4)
        $regularIds = ['Hn-GPYb6WB4', 'lLMGwlNc0xU', 'hn947xnfQ_4', 'D2ImaSpbyA8', 'X-T12_2uXCE', 'E-gxs1YJN6s', 'wLD6rwUIqn0', 'NkIo_bd65o4'];

        $foundRegular = false;
        foreach ($regularIds as $regularId) {
            if (str_contains($output, "New video found: {$regularId}")) {
                $foundRegular = true;
                break;
            }
        }

        $this->assertTrue($foundRegular, 'Nenhum dos vídeos regulares conhecidos do canal foi identificado e processado.');
    }

    public function test_check_channels_command_skips_youtube_shorts_for_ancapsu()
    {
        $channel = Channel::create([
            'youtube_id' => 'UCLTWPE7XrHEe8m_xAmNbQ-Q',
            'name' => 'ANCAPSU',
            'url' => 'https://www.youtube.com/@ancap_su',
            'download_quality' => '720p',
            'cutoff_date' => '2020-01-01',
        ]);

        Artisan::call('app:check-channels');

        $output = Artisan::output();

        $this->assertStringContainsString('YouTube Short', $output, 'Nenhum Short foi pulado ou reportado no output.');

        // IDs conhecidos de Shorts do canal @ancap_su (media_type "short" no yt-dlp)
        $shortIds = ['gwgmZwB1adc', 'DE3LnuEJKB8', '9oBhMeK8Wo0', 'rgsRGgbDcYQ', '60fUrg1YiM4'];

        $foundSkippedShort = false;
        foreach ($shortIds as $shortId) {
            if (str_contains($output, "Skipping video {$shortId}: YouTube Short.")) {
                $foundSkippedShort = true;
                $this->assertDatabaseMissing('videos', [
                    'youtube_id' => $shortId,
                ]);
            }
        }
        $this->assertTrue($foundSkippedShort, 'Nenhum dos Shorts conhecidos do canal foi identificado e pulado.');

        // Vídeos regulares (não Shorts) do mesmo canal devem continuar sendo cadastrados normalmente.
        $regularIds = ['x4u2X4nqIN4', 'q-5yrOkczoU', '5ieN4SK8-Bs'];

        $foundRegular = false;
        foreach ($regularIds as $regularId) {
            if (str_contains($output, "New video found: {$regularId}")) {
                $foundRegular = true;
                break;
            }
        }

        $this->assertTrue($foundRegular, 'Nenhum dos vídeos regulares conhecidos do canal foi identificado e processado.');
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
