<?php

namespace Tests\Feature;

use App\Models\Channel;
use App\Models\Setting;
use App\Models\User;
use App\Models\Video;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Number;
use Tests\TestCase;

class VideoShowTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        $downloadsDir = Setting::getStoragePath();
        foreach (['Main Channel', 'Other Channel'] as $dir) {
            if (file_exists($downloadsDir.'/'.$dir)) {
                exec('rm -rf '.escapeshellarg($downloadsDir.'/'.$dir));
            }
        }

        parent::tearDown();
    }

    public function test_video_show_page_has_player_and_related_video_sections()
    {
        $user = User::factory()->create();

        $mainChannel = Channel::create([
            'youtube_id' => 'UC_main_chan',
            'name' => 'Main Channel',
            'url' => 'https://example.com/main',
        ]);
        $otherChannel = Channel::create([
            'youtube_id' => 'UC_other_chan',
            'name' => 'Other Channel',
            'url' => 'https://example.com/other',
        ]);

        $downloadsDir = Setting::getStoragePath();
        $mainDir = $downloadsDir.'/Main Channel/Season 2026';
        $otherDir = $downloadsDir.'/Other Channel/Season 2026';
        mkdir($mainDir, 0755, true);
        mkdir($otherDir, 0755, true);

        $mainVideoRelPath = 'Main Channel/Season 2026/Main Channel - s2026e0710 - Target Video [target_vid].mp4';
        file_put_contents($downloadsDir.'/'.$mainVideoRelPath, 'fake video bytes');

        $video = Video::create([
            'channel_id' => $mainChannel->id,
            'youtube_id' => 'target_vid',
            'title' => 'Target Video',
            'description' => 'This is the video being watched.',
            'published_at' => now(),
            'status' => 'completed',
            'file_path' => $mainVideoRelPath,
        ]);

        $sameChannelVideo = Video::create([
            'channel_id' => $mainChannel->id,
            'youtube_id' => 'same_channel_vid',
            'title' => 'Another Main Channel Video',
            'published_at' => now(),
            'status' => 'completed',
        ]);

        // Pending video in the same channel must not show up as "related".
        Video::create([
            'channel_id' => $mainChannel->id,
            'youtube_id' => 'pending_same_channel_vid',
            'title' => 'Pending Same Channel Video',
            'published_at' => now(),
            'status' => 'pending',
        ]);

        $otherChannelVideo = Video::create([
            'channel_id' => $otherChannel->id,
            'youtube_id' => 'other_channel_vid',
            'title' => 'Suggested Other Channel Video',
            'published_at' => now(),
            'status' => 'completed',
        ]);

        $response = $this->actingAs($user)->get('/videos/'.$video->id);

        $response->assertStatus(200);
        $response->assertSee('Target Video');
        $response->assertSee('This is the video being watched.');
        $response->assertSee('<video', false);
        $response->assertSee('Another Main Channel Video');
        $response->assertSee('Suggested Other Channel Video');
        $response->assertDontSee('Pending Same Channel Video');
        $response->assertSee('Download Original File');
        $this->assertStringContainsString(
            'href="'.$video->videoUrl().'" download',
            $response->getContent()
        );
    }

    public function test_video_show_page_displays_publish_time_duration_file_size_and_youtube_link()
    {
        $user = User::factory()->create();

        $channel = Channel::create([
            'youtube_id' => 'UC_details_chan',
            'name' => 'Details Channel',
            'url' => 'https://example.com/details',
        ]);

        $downloadsDir = Setting::getStoragePath();
        $videoDir = $downloadsDir.'/Details Channel/Season 2026';
        mkdir($videoDir, 0755, true);

        $relativePath = 'Details Channel/Season 2026/Details Channel - s2026e0710 - Details Video [details_vid].mp4';
        file_put_contents($downloadsDir.'/'.$relativePath, str_repeat('a', 2048));

        $publishedAt = Carbon::create(2026, 7, 10, 14, 30, 0, 'UTC');
        $downloadedAt = Carbon::create(2026, 7, 10, 15, 0, 0, 'UTC');

        $video = Video::create([
            'channel_id' => $channel->id,
            'youtube_id' => 'details_vid',
            'title' => 'Details Video',
            'published_at' => $publishedAt,
            'duration' => 754, // 12:34
            'status' => 'completed',
            'file_path' => $relativePath,
            'downloaded_at' => $downloadedAt,
        ]);

        $response = $this->actingAs($user)->get('/videos/'.$video->id);

        $response->assertStatus(200);
        $response->assertSee($publishedAt->format('M d, Y'));
        $response->assertSee($publishedAt->format('g:i A'));
        $response->assertSee('12:34');
        $response->assertSee($downloadedAt->format('M d, Y'));
        $response->assertSee(Number::fileSize(2048, precision: 1));
        $response->assertSee('https://www.youtube.com/watch?v=details_vid', false);

        exec('rm -rf '.escapeshellarg($downloadsDir.'/Details Channel'));
    }

    public function test_video_without_file_shows_unavailable_message_instead_of_player()
    {
        $user = User::factory()->create();
        $channel = Channel::create([
            'youtube_id' => 'UC_nofile_chan',
            'name' => 'No File Channel',
            'url' => 'https://example.com/nofile',
        ]);

        $video = Video::create([
            'channel_id' => $channel->id,
            'youtube_id' => 'no_file_vid',
            'title' => 'Video Without File',
            'published_at' => now(),
            'status' => 'pending',
        ]);

        $response = $this->actingAs($user)->get('/videos/'.$video->id);

        $response->assertStatus(200);
        $response->assertSee('Video file not available.');
        $response->assertDontSee('<video', false);
        $response->assertDontSee('Download Original File');
    }

    public function test_retrying_a_failed_video_resets_it_back_to_pending()
    {
        $user = User::factory()->create();
        $channel = Channel::create([
            'youtube_id' => 'UC_retry_chan',
            'name' => 'Retry Channel',
            'url' => 'https://example.com/retry',
        ]);

        $video = Video::create([
            'channel_id' => $channel->id,
            'youtube_id' => 'retry_vid',
            'title' => 'Retry Video',
            'published_at' => now(),
            'status' => 'failed',
            'retries' => 3,
            'prevent_download' => true,
            'unavailable_reason' => 'Private video',
            'last_error' => 'Permanently unavailable: Private video',
        ]);

        $response = $this->actingAs($user)->post('/videos/'.$video->id.'/retry');

        $response->assertRedirect();

        $video->refresh();
        $this->assertSame('pending', $video->status);
        $this->assertSame(0, $video->retries);
        $this->assertFalse((bool) $video->prevent_download);
        $this->assertNull($video->unavailable_reason);
        $this->assertNull($video->last_error);
    }
}
