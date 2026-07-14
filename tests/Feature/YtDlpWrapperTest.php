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

        // These tests hit the real yt-dlp binary; the production safety delay between
        // requests would only slow the suite down without adding value.
        Setting::set('ytdlp_delay_seconds', '0');
    }

    public function test_can_retrieve_metadata_via_wrapper()
    {
        $wrapper = new YtDlpWrapper;

        $url = 'https://www.youtube.com/watch?v=qu0ViL6eChs';

        $fields = ['id', 'title', 'duration', 'upload_date', 'was_live', 'live_status'];

        $metadata = $wrapper->getMetadata($url, $fields, ['--playlist-items 1']);

        $this->assertNotNull($metadata, 'Metadata returned null.');
        $this->assertEquals('qu0ViL6eChs', $metadata['id']);
        $this->assertStringContainsString('Esses jogos foram Lan', $metadata['title']);
        $this->assertEquals(696, $metadata['duration']);
        $this->assertEquals('20260704', $metadata['upload_date']);
        $this->assertFalse($metadata['was_live']);
        $this->assertEquals('not_live', $metadata['live_status']);
    }

    public function test_metadata_is_cached_and_retrieved_from_cache_subsequently()
    {
        $wrapper = new YtDlpWrapper;
        $url = 'https://www.youtube.com/watch?v=qu0ViL6eChs';
        $fields = ['id', 'title'];

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
