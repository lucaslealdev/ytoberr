<?php

namespace Tests\Feature;

use App\Models\Channel;
use App\Models\Setting;
use App\Models\Video;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class BackfillFileSizeTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        $downloadsDir = Setting::getStoragePath();
        foreach (['Backfill File Size Channel'] as $dir) {
            if (file_exists($downloadsDir.'/'.$dir)) {
                exec('rm -rf '.escapeshellarg($downloadsDir.'/'.$dir));
            }
        }

        parent::tearDown();
    }

    public function test_backfill_populates_file_size_for_a_video_with_a_file_path_and_a_null_file_size()
    {
        $channel = Channel::create([
            'youtube_id' => 'UC_backfill_size_chan',
            'name' => 'Backfill File Size Channel',
            'url' => 'https://example.com/backfillsize',
        ]);

        $downloadsDir = Setting::getStoragePath();
        $videoDir = $downloadsDir.'/Backfill File Size Channel/Season 2026';
        mkdir($videoDir, 0755, true);

        $relativePath = 'Backfill File Size Channel/Season 2026/backfill-size.mp4';
        file_put_contents($downloadsDir.'/'.$relativePath, str_repeat('a', 12345));

        $video = Video::create([
            'channel_id' => $channel->id,
            'youtube_id' => 'backfill_size_vid',
            'title' => 'Backfill Size Video',
            'published_at' => '2026-07-10 12:00:00',
            'status' => 'completed',
            'file_path' => $relativePath,
        ]);

        $this->assertNull($video->file_size);

        Artisan::call('operations:process', ['--sync' => true]);

        $video->refresh();
        $this->assertEquals(12345, $video->file_size);
    }

    public function test_backfill_leaves_an_already_populated_file_size_untouched()
    {
        $channel = Channel::create([
            'youtube_id' => 'UC_backfill_size_skip_chan',
            'name' => 'Backfill Size Skip Channel',
            'url' => 'https://example.com/backfillsizeskip',
        ]);

        $video = Video::create([
            'channel_id' => $channel->id,
            'youtube_id' => 'backfill_size_skip_vid',
            'title' => 'Backfill Size Skip Video',
            'published_at' => '2026-07-10 12:00:00',
            'status' => 'completed',
            'file_path' => 'Backfill Size Skip Channel/Season 2026/already-known.mp4',
            'file_size' => 9999,
        ]);

        Artisan::call('operations:process', ['--sync' => true]);

        $video->refresh();
        $this->assertEquals(9999, $video->file_size);
    }

    public function test_backfill_logs_a_warning_and_leaves_file_size_null_when_the_file_is_missing_on_disk()
    {
        Log::spy();

        $channel = Channel::create([
            'youtube_id' => 'UC_backfill_size_missing_chan',
            'name' => 'Backfill Size Missing Channel',
            'url' => 'https://example.com/backfillsizemissing',
        ]);

        $video = Video::create([
            'channel_id' => $channel->id,
            'youtube_id' => 'backfill_size_missing_vid',
            'title' => 'Backfill Size Missing Video',
            'published_at' => '2026-07-10 12:00:00',
            'status' => 'completed',
            'file_path' => 'Backfill Size Missing Channel/Season 2026/missing.mp4',
        ]);

        Artisan::call('operations:process', ['--sync' => true]);

        $video->refresh();
        $this->assertNull($video->file_size);
        Log::shouldHaveReceived('warning')->once();
    }

    public function test_backfill_ignores_videos_without_a_file_path()
    {
        $channel = Channel::create([
            'youtube_id' => 'UC_backfill_size_nofile_chan',
            'name' => 'Backfill Size No File Channel',
            'url' => 'https://example.com/backfillsizenofile',
        ]);

        $video = Video::create([
            'channel_id' => $channel->id,
            'youtube_id' => 'backfill_size_nofile_vid',
            'title' => 'Backfill Size No File Video',
            'published_at' => '2026-07-10 12:00:00',
            'status' => 'pending',
        ]);

        Artisan::call('operations:process', ['--sync' => true]);

        $video->refresh();
        $this->assertNull($video->file_size);
    }
}
