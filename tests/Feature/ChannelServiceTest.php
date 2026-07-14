<?php

namespace Tests\Feature;

use App\Models\Channel;
use App\Services\ChannelService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ChannelServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
    }

    private function mockYtDlp(string $name, string $script): string
    {
        $path = storage_path('app/temp/'.$name);
        file_put_contents($path, $script);
        chmod($path, 0755);
        config(['services.ytdlp_path' => $path]);

        return $path;
    }

    public function test_fetch_and_store_channel_images_saves_poster_fanart_and_banner()
    {
        $channel = Channel::create([
            'youtube_id' => 'UC_channel_images_chan',
            'name' => 'Channel Images Channel',
            'url' => 'https://example.com/channelimages',
        ]);

        $mockYtDlp = $this->mockYtDlp('mock_ytdlp_images_ok.sh', <<<'BASH'
#!/bin/bash
for arg in "$@"; do
    if [[ $arg == *source_image.* ]]; then
        out_dir=$(dirname "$arg")
        mkdir -p "$out_dir"
        cat > "$out_dir/source_image.info.json" <<'JSON'
{"thumbnails": [
    {"id": "avatar_uncropped", "width": 800, "height": 800},
    {"id": "banner_uncropped", "width": 1280, "height": 720},
    {"id": "wide1", "width": 1280, "height": 200}
]}
JSON
        echo "avatar bytes" > "$out_dir/source_image.avatar_uncropped.jpg"
        echo "banner bytes" > "$out_dir/source_image.banner_uncropped.jpg"
        echo "wide bytes" > "$out_dir/source_image.wide1.jpg"
        exit 0
    fi
done
exit 1
BASH);

        app(ChannelService::class)->fetchAndStoreChannelImages($channel);

        Storage::disk('public')->assertExists("channels/{$channel->id}/poster.jpg");
        Storage::disk('public')->assertExists("channels/{$channel->id}/fanart.jpg");
        Storage::disk('public')->assertExists("channels/{$channel->id}/banner.jpg");

        $this->assertSame('avatar bytes', trim(Storage::disk('public')->get("channels/{$channel->id}/poster.jpg")));
        $this->assertSame('banner bytes', trim(Storage::disk('public')->get("channels/{$channel->id}/fanart.jpg")));
        $this->assertSame('wide bytes', trim(Storage::disk('public')->get("channels/{$channel->id}/banner.jpg")));

        unlink($mockYtDlp);
    }

    public function test_fetch_and_store_channel_images_falls_back_to_widest_thumbnail_when_no_avatar_present()
    {
        $channel = Channel::create([
            'youtube_id' => 'UC_channel_images_fallback_chan',
            'name' => 'Channel Images Fallback Channel',
            'url' => 'https://example.com/channelimagesfallback',
        ]);

        $mockYtDlp = $this->mockYtDlp('mock_ytdlp_images_fallback.sh', <<<'BASH'
#!/bin/bash
for arg in "$@"; do
    if [[ $arg == *source_image.* ]]; then
        out_dir=$(dirname "$arg")
        mkdir -p "$out_dir"
        cat > "$out_dir/source_image.info.json" <<'JSON'
{"thumbnails": [
    {"id": "small", "width": 120, "height": 90},
    {"id": "large", "width": 640, "height": 480}
]}
JSON
        echo "small bytes" > "$out_dir/source_image.small.jpg"
        echo "large bytes" > "$out_dir/source_image.large.jpg"
        exit 0
    fi
done
exit 1
BASH);

        app(ChannelService::class)->fetchAndStoreChannelImages($channel);

        Storage::disk('public')->assertExists("channels/{$channel->id}/poster.jpg");
        $this->assertSame('large bytes', trim(Storage::disk('public')->get("channels/{$channel->id}/poster.jpg")));

        unlink($mockYtDlp);
    }

    public function test_fetch_and_store_channel_images_logs_warning_when_ytdlp_exits_with_error()
    {
        $channel = Channel::create([
            'youtube_id' => 'UC_channel_images_fail_chan',
            'name' => 'Channel Images Fail Channel',
            'url' => 'https://example.com/channelimagesfail',
        ]);

        $mockYtDlp = $this->mockYtDlp('mock_ytdlp_images_fail.sh', <<<'BASH'
#!/bin/bash
echo "ERROR: General Connection Error"
exit 1
BASH);

        app(ChannelService::class)->fetchAndStoreChannelImages($channel);

        $this->assertDatabaseHas('warnings', ['source' => 'channel_images_fetch_failed']);
        Storage::disk('public')->assertMissing("channels/{$channel->id}/poster.jpg");

        unlink($mockYtDlp);
    }

    public function test_fetch_and_store_channel_images_logs_warning_when_metadata_json_is_missing_thumbnails()
    {
        $channel = Channel::create([
            'youtube_id' => 'UC_channel_images_malformed_chan',
            'name' => 'Channel Images Malformed Channel',
            'url' => 'https://example.com/channelimagesmalformed',
        ]);

        $mockYtDlp = $this->mockYtDlp('mock_ytdlp_images_malformed.sh', <<<'BASH'
#!/bin/bash
for arg in "$@"; do
    if [[ $arg == *source_image.* ]]; then
        out_dir=$(dirname "$arg")
        mkdir -p "$out_dir"
        echo '{"some_other_key": true}' > "$out_dir/source_image.info.json"
        exit 0
    fi
done
exit 1
BASH);

        app(ChannelService::class)->fetchAndStoreChannelImages($channel);

        $this->assertDatabaseHas('warnings', ['source' => 'channel_images_fetch_failed']);
        Storage::disk('public')->assertMissing("channels/{$channel->id}/poster.jpg");

        unlink($mockYtDlp);
    }
}
