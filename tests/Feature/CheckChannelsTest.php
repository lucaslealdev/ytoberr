<?php

namespace Tests\Feature;

use App\Models\Channel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class CheckChannelsTest extends TestCase
{
    use RefreshDatabase;

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
}
