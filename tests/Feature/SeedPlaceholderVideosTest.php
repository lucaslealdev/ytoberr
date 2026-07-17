<?php

namespace Tests\Feature;

use App\Models\Channel;
use App\Models\Setting;
use App\Models\Video;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class SeedPlaceholderVideosTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        $downloadsDir = Setting::getStoragePath();
        if (file_exists($downloadsDir.'/Placeholder Demo Channel')) {
            exec('rm -rf '.escapeshellarg($downloadsDir.'/Placeholder Demo Channel'));
        }

        parent::tearDown();
    }

    public function test_seeds_the_requested_number_of_placeholder_videos_with_real_thumbnails()
    {
        Artisan::call('dev:seed-placeholder-videos', ['--count' => 5, '--force' => true]);

        $channel = Channel::where('name', 'Placeholder Demo Channel')->first();
        $this->assertNotNull($channel);
        $this->assertSame(5, Video::where('channel_id', $channel->id)->count());

        $video = Video::where('channel_id', $channel->id)->first();
        $this->assertSame('completed', $video->status);
        $this->assertNotNull($video->file_size);

        $downloadsDir = Setting::getStoragePath();
        $thumbPath = $downloadsDir.'/'.$video->thumbnail_path;
        $this->assertFileExists($thumbPath);
        $this->assertStringContainsString('JPEG', shell_exec('file '.escapeshellarg($thumbPath)));
    }

    public function test_running_it_twice_does_not_duplicate_videos()
    {
        Artisan::call('dev:seed-placeholder-videos', ['--count' => 5, '--force' => true]);
        Artisan::call('dev:seed-placeholder-videos', ['--count' => 5, '--force' => true]);

        $channel = Channel::where('name', 'Placeholder Demo Channel')->first();
        $this->assertSame(5, Video::where('channel_id', $channel->id)->count());
    }

    public function test_clear_removes_only_the_placeholder_channel_and_videos()
    {
        $realChannel = Channel::create([
            'youtube_id' => 'UC_real_untouched_chan',
            'name' => 'Real Channel',
            'url' => 'https://example.com/realchannel',
        ]);
        $realVideo = Video::create([
            'channel_id' => $realChannel->id,
            'youtube_id' => 'real_untouched_vid',
            'title' => 'Real Video',
            'published_at' => now(),
            'status' => 'completed',
        ]);

        Artisan::call('dev:seed-placeholder-videos', ['--count' => 5, '--force' => true]);
        Artisan::call('dev:seed-placeholder-videos', ['--clear' => true, '--force' => true]);

        $this->assertNull(Channel::where('name', 'Placeholder Demo Channel')->first());
        $this->assertSame(0, Video::where('youtube_id', 'like', 'placeholder_demo_vid_%')->count());
        $this->assertNotNull(Channel::find($realChannel->id));
        $this->assertNotNull(Video::find($realVideo->id));
    }
}
