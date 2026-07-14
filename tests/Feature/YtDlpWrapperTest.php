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
