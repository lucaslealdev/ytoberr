<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Models\User;
use App\Models\YtDlpCache;
use App\Services\YtDlpWrapper;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class YtDlpWrapperTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // yt-dlp is mocked in this test class (see mockYtDlpMetadata()); the production
        // safety delay between requests would only slow the suite down without adding value.
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

    public function test_can_retrieve_metadata_via_wrapper()
    {
        $wrapper = new YtDlpWrapper;

        $url = 'https://www.youtube.com/watch?v=placeholder_video_id';

        $fields = ['id', 'title', 'duration', 'upload_date', 'was_live', 'live_status'];

        $mockYtDlp = $this->mockYtDlpMetadata('metadata_basic', [
            'id' => 'placeholder_video_id',
            'title' => 'Placeholder Video Title',
            'duration' => 696,
            'upload_date' => '20260704',
            'was_live' => false,
            'live_status' => 'not_live',
        ]);
        config(['services.ytdlp_path' => $mockYtDlp]);

        $metadata = $wrapper->getMetadata($url, $fields, ['--playlist-items 1']);

        $this->assertNotNull($metadata, 'Metadata returned null.');
        $this->assertEquals('placeholder_video_id', $metadata['id']);
        $this->assertStringContainsString('Placeholder Video Title', $metadata['title']);
        $this->assertEquals(696, $metadata['duration']);
        $this->assertEquals('20260704', $metadata['upload_date']);
        $this->assertFalse($metadata['was_live']);
        $this->assertEquals('not_live', $metadata['live_status']);

        unlink($mockYtDlp);
    }

    public function test_metadata_is_cached_and_retrieved_from_cache_subsequently()
    {
        $wrapper = new YtDlpWrapper;
        $url = 'https://www.youtube.com/watch?v=placeholder_video_id';
        $fields = ['id', 'title'];

        $mockYtDlp = $this->mockYtDlpMetadata('metadata_cache', [
            'id' => 'placeholder_video_id',
            'title' => 'Placeholder Video Title',
        ]);
        config(['services.ytdlp_path' => $mockYtDlp]);

        // Confirm cache is empty
        $this->assertEquals(0, YtDlpCache::count());

        // First call should run yt-dlp and store in cache
        $metadataFirst = $wrapper->getMetadata($url, $fields, ['--playlist-items 1']);
        $this->assertNotNull($metadataFirst);
        $this->assertEquals(1, YtDlpCache::count());

        // Corrupt the cached value in the DB to prove subsequent call uses the cache
        $cacheEntry = YtDlpCache::first();
        $cacheEntry->update([
            'value' => ['id' => 'cached_id_123', 'title' => 'Cached Title Override'],
        ]);

        // Second call should return the mutated cache value directly (instantly!)
        $metadataSecond = $wrapper->getMetadata($url, $fields, ['--playlist-items 1']);

        $this->assertEquals('cached_id_123', $metadataSecond['id']);
        $this->assertEquals('Cached Title Override', $metadataSecond['title']);

        unlink($mockYtDlp);
    }

    public function test_run_command_kills_the_process_instead_of_leaving_it_running_when_it_times_out()
    {
        // Regression test for a production incident: PHP's exec() has no way to kill a child
        // process once it's running, so a command that overran the queue job's timeout kept
        // running as an orphan, competing with (and slowing down) every retry until the
        // server ran out of capacity. runCommand() must actually terminate the process tree.
        $script = storage_path('app/temp/mock_slow_command.sh');
        $pidFile = storage_path('app/temp/mock_slow_command.pid');
        if (file_exists($pidFile)) {
            unlink($pidFile);
        }

        file_put_contents($script, <<<BASH
#!/bin/bash
echo \$\$ > {$pidFile}
sleep 10
BASH);
        chmod($script, 0755);

        $wrapper = new YtDlpWrapper;

        $start = microtime(true);
        [$output, $resultCode] = $wrapper->runCommand($script, 1);
        $elapsed = microtime(true) - $start;

        $this->assertLessThan(8, $elapsed, 'runCommand() should return shortly after the 1s timeout, not wait out the full 10s sleep.');
        $this->assertEquals(124, $resultCode);

        $this->assertFileExists($pidFile);
        $pid = (int) trim(file_get_contents($pidFile));

        // Give the OS a brief moment to fully reap the killed process before checking.
        usleep(300000);
        $this->assertFalse(posix_kill($pid, 0), 'The spawned process should have been killed, not left running as an orphan.');

        unlink($script);
        unlink($pidFile);
    }

    public function test_can_reset_ytdlp_cache()
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        YtDlpCache::create([
            'key' => 'test_key',
            'value' => ['id' => '123'],
            'expires_at' => now()->addMinutes(30),
        ]);

        $this->assertEquals(1, YtDlpCache::count());

        $response = $this->post('/settings/reset-cache');

        $response->assertStatus(302); // Redirect back
        $this->assertEquals(0, YtDlpCache::count());
    }
}
