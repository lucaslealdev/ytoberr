<?php

namespace Tests\Feature;

use App\Models\Channel;
use App\Models\Video;
use App\Services\PlexAssetService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class PlexAssetServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
    }

    private function tempChannelDir(string $suffix): string
    {
        return storage_path('app/temp/plex-asset-test-'.$suffix.'-'.Str::random(8));
    }

    public function test_sync_channel_assets_copies_stored_images_and_writes_tvshow_nfo()
    {
        $channel = Channel::create([
            'youtube_id' => 'UC_plex_asset_chan',
            'name' => 'Plex Asset Channel',
            'url' => 'https://example.com/plexasset',
            'description' => 'A channel for testing Plex assets.',
        ]);

        Storage::disk('public')->put("channels/{$channel->id}/poster.jpg", 'poster bytes');
        Storage::disk('public')->put("channels/{$channel->id}/fanart.jpg", 'fanart bytes');
        Storage::disk('public')->put("channels/{$channel->id}/banner.jpg", 'banner bytes');

        $channelDir = $this->tempChannelDir('full');

        try {
            app(PlexAssetService::class)->syncChannelAssets($channel, $channelDir);

            $this->assertFileExists($channelDir.'/poster.jpg');
            $this->assertSame('poster bytes', file_get_contents($channelDir.'/poster.jpg'));
            $this->assertFileExists($channelDir.'/fanart.jpg');
            $this->assertSame('fanart bytes', file_get_contents($channelDir.'/fanart.jpg'));
            $this->assertFileExists($channelDir.'/banner.jpg');
            $this->assertSame('banner bytes', file_get_contents($channelDir.'/banner.jpg'));

            $this->assertFileExists($channelDir.'/tvshow.nfo');
            $xml = simplexml_load_file($channelDir.'/tvshow.nfo');
            $this->assertSame('Plex Asset Channel', (string) $xml->title);
            $this->assertSame('A channel for testing Plex assets.', (string) $xml->plot);
            $this->assertSame('UC_plex_asset_chan', (string) $xml->uniqueid);
            $this->assertSame('youtube', (string) $xml->uniqueid['type']);
            $this->assertSame('YouTube', (string) $xml->genre);
        } finally {
            exec('rm -rf '.escapeshellarg($channelDir));
        }
    }

    public function test_sync_channel_assets_creates_the_target_directory_when_missing()
    {
        $channel = Channel::create([
            'youtube_id' => 'UC_plex_asset_missing_chan',
            'name' => 'Plex Asset Missing Dir Channel',
            'url' => 'https://example.com/plexassetmissingdir',
        ]);

        $channelDir = $this->tempChannelDir('missing-dir');
        $this->assertDirectoryDoesNotExist($channelDir);

        try {
            app(PlexAssetService::class)->syncChannelAssets($channel, $channelDir);

            $this->assertDirectoryExists($channelDir);
            $this->assertFileExists($channelDir.'/tvshow.nfo');
        } finally {
            exec('rm -rf '.escapeshellarg($channelDir));
        }
    }

    public function test_sync_channel_assets_skips_missing_images_without_erroring()
    {
        $channel = Channel::create([
            'youtube_id' => 'UC_plex_asset_noimg_chan',
            'name' => 'Plex Asset No Image Channel',
            'url' => 'https://example.com/plexassetnoimg',
        ]);

        $channelDir = $this->tempChannelDir('no-images');

        try {
            app(PlexAssetService::class)->syncChannelAssets($channel, $channelDir);

            $this->assertFileDoesNotExist($channelDir.'/poster.jpg');
            $this->assertFileDoesNotExist($channelDir.'/fanart.jpg');
            $this->assertFileDoesNotExist($channelDir.'/banner.jpg');
            $this->assertFileExists($channelDir.'/tvshow.nfo');
        } finally {
            exec('rm -rf '.escapeshellarg($channelDir));
        }
    }

    public function test_write_video_nfo_writes_the_expected_fields()
    {
        $channel = Channel::create([
            'youtube_id' => 'UC_video_nfo_chan',
            'name' => 'Video NFO Channel',
            'url' => 'https://example.com/videonfo',
        ]);

        $video = Video::create([
            'channel_id' => $channel->id,
            'youtube_id' => 'nfo_vid_1',
            'title' => 'NFO Test Video',
            'description' => 'A video for testing NFO writing.',
            'published_at' => '2026-03-05 10:00:00',
            'status' => 'completed',
        ]);
        $video->load('channel');

        $path = storage_path('app/temp/video-nfo-test-'.Str::random(8).'.nfo');

        try {
            app(PlexAssetService::class)->writeVideoNfo($video, $path, 2026, '030599');

            $this->assertFileExists($path);
            $xml = simplexml_load_file($path);
            $this->assertSame('NFO Test Video', (string) $xml->title);
            $this->assertSame('Video NFO Channel', (string) $xml->showtitle);
            $this->assertSame('nfo_vid_1', (string) $xml->uniqueid);
            $this->assertSame('youtube', (string) $xml->uniqueid['type']);
            $this->assertSame('A video for testing NFO writing.', (string) $xml->plot);
            $this->assertSame('2026-03-05', (string) $xml->aired);
            $this->assertSame('2026', (string) $xml->season);
            $this->assertSame('030599', (string) $xml->episode);
            $this->assertSame('YouTube', (string) $xml->genre);
        } finally {
            if (file_exists($path)) {
                unlink($path);
            }
        }
    }
}
