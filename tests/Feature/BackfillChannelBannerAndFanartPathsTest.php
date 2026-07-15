<?php

namespace Tests\Feature;

use App\Models\Channel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;
use TimoKoerber\LaravelOneTimeOperations\OneTimeOperationManager;

class BackfillChannelBannerAndFanartPathsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
    }

    private function runOperation(): void
    {
        OneTimeOperationManager::getClassObjectByName('2026_07_15_010824_backfill_channel_banner_and_fanart_paths')->process();
    }

    public function test_backfill_populates_banner_and_fanart_paths_for_channels_with_existing_files_on_disk()
    {
        $channel = Channel::create([
            'youtube_id' => 'UC_backfill_both_chan',
            'name' => 'Backfill Both Channel',
            'url' => 'https://example.com/backfillboth',
        ]);

        Storage::disk('public')->put("channels/{$channel->id}/banner.jpg", 'banner bytes');
        Storage::disk('public')->put("channels/{$channel->id}/fanart.jpg", 'fanart bytes');

        $this->runOperation();

        $channel->refresh();
        $this->assertSame("channels/{$channel->id}/banner.jpg", $channel->banner_path);
        $this->assertSame("channels/{$channel->id}/fanart.jpg", $channel->fanart_path);
    }

    public function test_backfill_only_populates_the_column_for_the_file_that_actually_exists_on_disk()
    {
        $channel = Channel::create([
            'youtube_id' => 'UC_backfill_banner_only_chan',
            'name' => 'Backfill Banner Only Channel',
            'url' => 'https://example.com/backfillbanneronly',
        ]);

        Storage::disk('public')->put("channels/{$channel->id}/banner.jpg", 'banner bytes');

        $this->runOperation();

        $channel->refresh();
        $this->assertSame("channels/{$channel->id}/banner.jpg", $channel->banner_path);
        $this->assertNull($channel->fanart_path);
    }

    public function test_backfill_leaves_both_columns_null_when_neither_file_exists_on_disk()
    {
        $channel = Channel::create([
            'youtube_id' => 'UC_backfill_none_chan',
            'name' => 'Backfill None Channel',
            'url' => 'https://example.com/backfillnone',
        ]);

        $this->runOperation();

        $channel->refresh();
        $this->assertNull($channel->banner_path);
        $this->assertNull($channel->fanart_path);
    }

    public function test_backfill_is_safe_to_run_against_a_channel_already_populated_by_channel_service()
    {
        $channel = Channel::create([
            'youtube_id' => 'UC_backfill_idempotent_chan',
            'name' => 'Backfill Idempotent Channel',
            'url' => 'https://example.com/backfillidempotent',
        ]);

        Storage::disk('public')->put("channels/{$channel->id}/banner.jpg", 'banner bytes');
        $channel->update(['banner_path' => "channels/{$channel->id}/banner.jpg"]);

        $this->runOperation();

        $channel->refresh();
        $this->assertSame("channels/{$channel->id}/banner.jpg", $channel->banner_path);
        $this->assertNull($channel->fanart_path);
    }
}
