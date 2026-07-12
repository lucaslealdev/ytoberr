<?php

namespace Tests\Feature;

use App\Models\Channel;
use App\Models\Video;
use App\Models\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class DownloadNextVideoTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
    }

    protected function tearDown(): void
    {
        // DownloadNextVideo writes to the real downloads dir (not the faked disk), so clean up after ourselves.
        $downloadsDir = Setting::getStoragePath();
        if (file_exists($downloadsDir . '/Space Channel')) {
            exec('rm -rf ' . escapeshellarg($downloadsDir . '/Space Channel'));
        }

        parent::tearDown();
    }

    public function test_downloader_successfully_downloads_video()
    {
        $channel = Channel::create([
            'youtube_id' => 'UC_test_chan',
            'name' => 'Space Channel',
            'url' => 'https://example.com/space',
            'description' => 'A channel about space exploration.',
        ]);

        // Seed a fake channel poster so we can assert it gets copied into the Plex show folder.
        Storage::disk('public')->put('channels/' . $channel->id . '/poster.jpg', 'fake-poster-bytes');

        $video = Video::create([
            'channel_id' => $channel->id,
            'youtube_id' => 'space_vid_123',
            'title' => 'Landing on Mars',
            'description' => 'Humanity lands on Mars for the first time.',
            'published_at' => '2026-07-10 12:00:00',
            'status' => 'pending',
        ]);

        // Create a mock yt-dlp bash wrapper that writes dummy files
        $mockYtDlp = storage_path('app/temp/mock_ytdlp_download.sh');
        file_put_contents($mockYtDlp, <<<'BASH'
#!/bin/bash
# Find the --output argument value and write dummy files next to it
for arg in "$@"; do
    if [[ $arg == *video.* ]]; then
        out_dir=$(dirname "$arg")
        mkdir -p "$out_dir"
        echo "dummy video" > "$out_dir/video.mp4"
        echo "dummy thumb" > "$out_dir/video.jpg"
        echo "{}" > "$out_dir/video.info.json"
        exit 0
    fi
done
exit 1
BASH);
        chmod($mockYtDlp, 0755);

        // Mock config for yt-dlp path to avoid real execution
        config(['services.ytdlp_path' => $mockYtDlp]);

        // Execute command
        Artisan::call('videos:download');

        // Confirm database records are correctly updated
        $video->refresh();
        $this->assertEquals('completed', $video->status);
        $this->assertNotNull($video->file_path);

        // Assert relative file path structure targets Plex:
        // {downloads_dir}/{canal}/Season {YYYY}/{nome-do-arquivo}.{ext}
        $this->assertStringContainsString('Space Channel/Season 2026', $video->file_path);
        $this->assertStringContainsString('Landing on Mars [space_vid_123].mp4', $video->file_path);

        // Confirm thumbnail is copied as local asset
        $this->assertNotNull($video->thumbnail_path);
        $this->assertStringContainsString('Landing on Mars [space_vid_123]-thumb.jpg', $video->thumbnail_path);

        // Confirm Plex/Kodi local assets were written
        $downloadsDir = Setting::getStoragePath();
        $channelDir = $downloadsDir . '/Space Channel';

        $this->assertFileExists($channelDir . '/tvshow.nfo');
        $tvshowXml = simplexml_load_file($channelDir . '/tvshow.nfo');
        $this->assertEquals('Space Channel', (string) $tvshowXml->title);
        $this->assertEquals('A channel about space exploration.', (string) $tvshowXml->plot);
        $this->assertEquals('poster.jpg', (string) $tvshowXml->thumb);
        $this->assertEquals('poster', (string) $tvshowXml->thumb['aspect']);

        $this->assertFileExists($channelDir . '/poster.jpg');
        $this->assertEquals('fake-poster-bytes', file_get_contents($channelDir . '/poster.jpg'));

        $videoNfoPath = $downloadsDir . '/' . str_replace('.mp4', '.nfo', $video->file_path);
        $this->assertFileExists($videoNfoPath);
        $videoXml = simplexml_load_file($videoNfoPath);
        $this->assertEquals('Landing on Mars', (string) $videoXml->title);
        $this->assertEquals('Humanity lands on Mars for the first time.', (string) $videoXml->plot);
        $this->assertEquals('Space Channel - s2026e0710 - Landing on Mars [space_vid_123]-thumb.jpg', (string) $videoXml->thumb);
        $this->assertEquals('2026', (string) $videoXml->season);
        $this->assertEquals('0710', (string) $videoXml->episode);
        $this->assertEquals('space_vid_123', (string) $videoXml->uniqueid);

        unlink($mockYtDlp);
    }


    public function test_downloader_handles_permanently_unavailable_videos()
    {
        $channel = Channel::create([
            'youtube_id' => 'UC_test_chan',
            'name' => 'Space Channel',
            'url' => 'https://example.com/space',
        ]);

        $video = Video::create([
            'channel_id' => $channel->id,
            'youtube_id' => 'space_private',
            'title' => 'Secret Video',
            'published_at' => '2026-07-10 12:00:00',
            'status' => 'pending',
        ]);

        // Mock yt-dlp shell returning "Private Video" error code 1
        $mockYtDlp = storage_path('app/temp/mock_ytdlp_private.sh');
        file_put_contents($mockYtDlp, '#!/bin/bash
echo "ERROR: [youtube] space_private: Private video. Sign in if you have been granted access to this video"
exit 1
');
        chmod($mockYtDlp, 0755);
        config(['services.ytdlp_path' => $mockYtDlp]);

        Artisan::call('videos:download');

        $video->refresh();
        $this->assertEquals('failed', $video->status);
        $this->assertTrue((bool)$video->prevent_download);
        $this->assertEquals('Private video', $video->unavailable_reason);
        $this->assertStringContainsString('Permanently unavailable', $video->last_error);

        unlink($mockYtDlp);
    }

    public function test_downloader_suspends_queue_on_three_consecutive_failures()
    {
        $channel = Channel::create([
            'youtube_id' => 'UC_test_chan',
            'name' => 'Space Channel',
            'url' => 'https://example.com/space',
        ]);

        // Create 4 pending videos
        $v1 = Video::create(['channel_id' => $channel->id, 'youtube_id' => 'v1', 'title' => 'V1', 'published_at' => now(), 'status' => 'pending']);
        $v2 = Video::create(['channel_id' => $channel->id, 'youtube_id' => 'v2', 'title' => 'V2', 'published_at' => now(), 'status' => 'pending']);
        $v3 = Video::create(['channel_id' => $channel->id, 'youtube_id' => 'v3', 'title' => 'V3', 'published_at' => now(), 'status' => 'pending']);
        $v4 = Video::create(['channel_id' => $channel->id, 'youtube_id' => 'v4', 'title' => 'V4', 'published_at' => now(), 'status' => 'pending']);

        // Mock yt-dlp shell always returning error
        $mockYtDlp = storage_path('app/temp/mock_ytdlp_error.sh');
        file_put_contents($mockYtDlp, '#!/bin/bash
echo "ERROR: General Connection Error"
exit 1
');
        chmod($mockYtDlp, 0755);
        config(['services.ytdlp_path' => $mockYtDlp]);

        // Reset consecutive failures to 0
        Setting::set('consecutive_failures', '0');

        // Run download command 3 times (since each run downloads only 1 video)
        Artisan::call('videos:download'); // Fails v1 -> failures = 1
        Artisan::call('videos:download'); // Fails v2 -> failures = 2
        Artisan::call('videos:download'); // Fails v3 -> failures = 3 (suspends queue)

        // On the third failure, the queue suspends, so v4 and any remaining pending videos are marked as failed
        $v1->refresh(); $v2->refresh(); $v3->refresh(); $v4->refresh();

        $this->assertEquals('failed', $v1->status);
        $this->assertEquals('failed', $v2->status);
        $this->assertEquals('failed', $v3->status);
        $this->assertEquals('failed', $v4->status);
        $this->assertStringContainsString('Queue suspended', $v4->last_error);

        unlink($mockYtDlp);
    }
}
