<?php

namespace Tests\Feature;

use App\Models\Channel;
use App\Models\Setting;
use App\Models\Video;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class VideoTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        $downloadsDir = Setting::getStoragePath();
        foreach (['File Size Fallback Channel'] as $dir) {
            if (file_exists($downloadsDir.'/'.$dir)) {
                exec('rm -rf '.escapeshellarg($downloadsDir.'/'.$dir));
            }
        }

        parent::tearDown();
    }

    public function test_file_size_returns_the_cached_column_value_without_needing_the_file_on_disk()
    {
        $channel = Channel::create([
            'youtube_id' => 'UC_file_size_cached_chan',
            'name' => 'File Size Cached Channel',
            'url' => 'https://example.com/filesizecached',
        ]);

        // file_path points at a file that was never created on disk: if fileSize() ignored the
        // cached column here, it would return null instead of the stored value.
        $video = Video::create([
            'channel_id' => $channel->id,
            'youtube_id' => 'file_size_cached_vid',
            'title' => 'File Size Cached Video',
            'published_at' => now(),
            'status' => 'completed',
            'file_path' => 'File Size Cached Channel/Season 2026/missing.mp4',
            'file_size' => 123456,
        ]);

        $this->assertSame(123456, $video->fileSize());
    }

    public function test_file_size_falls_back_to_a_live_filesystem_stat_when_the_column_is_null()
    {
        $channel = Channel::create([
            'youtube_id' => 'UC_file_size_fallback_chan',
            'name' => 'File Size Fallback Channel',
            'url' => 'https://example.com/filesizefallback',
        ]);

        $downloadsDir = Setting::getStoragePath();
        $videoDir = $downloadsDir.'/File Size Fallback Channel/Season 2026';
        mkdir($videoDir, 0755, true);

        $relativePath = 'File Size Fallback Channel/Season 2026/fallback.mp4';
        file_put_contents($downloadsDir.'/'.$relativePath, str_repeat('a', 4096));

        $video = Video::create([
            'channel_id' => $channel->id,
            'youtube_id' => 'file_size_fallback_vid',
            'title' => 'File Size Fallback Video',
            'published_at' => now(),
            'status' => 'completed',
            'file_path' => $relativePath,
            // file_size intentionally left null, as it would be for a pre-migration video.
        ]);

        $this->assertNull($video->file_size);
        $this->assertSame(4096, $video->fileSize());
    }

    public function test_file_size_returns_null_when_neither_the_column_nor_the_file_are_present()
    {
        $channel = Channel::create([
            'youtube_id' => 'UC_file_size_missing_chan',
            'name' => 'File Size Missing Channel',
            'url' => 'https://example.com/filesizemissing',
        ]);

        $video = Video::create([
            'channel_id' => $channel->id,
            'youtube_id' => 'file_size_missing_vid',
            'title' => 'File Size Missing Video',
            'published_at' => now(),
            'status' => 'pending',
        ]);

        $this->assertNull($video->fileSize());
    }

    public function test_formatted_duration_formats_seconds_as_hms_or_ms()
    {
        $channel = Channel::create([
            'youtube_id' => 'UC_duration_fmt_chan',
            'name' => 'Duration Format Channel',
            'url' => 'https://example.com/durationformat',
        ]);

        $cases = [
            [45, '0:45'],
            [125, '2:05'],
            [3661, '1:01:01'],
            [null, null],
        ];

        foreach ($cases as $i => [$seconds, $expected]) {
            $video = Video::create([
                'channel_id' => $channel->id,
                'youtube_id' => 'duration_fmt_vid_'.$i,
                'title' => 'Duration Format Test',
                'published_at' => now(),
                'duration' => $seconds,
            ]);

            $this->assertSame($expected, $video->formattedDuration(), "Failed for duration={$seconds}");
        }
    }
}
