<?php

namespace Tests\Feature;

use App\Models\Channel;
use App\Models\Setting;
use App\Models\User;
use App\Models\Video;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class VideoShowTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        $downloadsDir = Setting::getStoragePath();
        foreach (['Main Channel', 'Other Channel'] as $dir) {
            if (file_exists($downloadsDir . '/' . $dir)) {
                exec('rm -rf ' . escapeshellarg($downloadsDir . '/' . $dir));
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
        $mainDir = $downloadsDir . '/Main Channel/Season 2026';
        $otherDir = $downloadsDir . '/Other Channel/Season 2026';
        mkdir($mainDir, 0755, true);
        mkdir($otherDir, 0755, true);

        $mainVideoRelPath = 'Main Channel/Season 2026/Main Channel - s2026e0710 - Target Video [target_vid].mp4';
        file_put_contents($downloadsDir . '/' . $mainVideoRelPath, 'fake video bytes');

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

        $response = $this->actingAs($user)->get('/videos/' . $video->id);

        $response->assertStatus(200);
        $response->assertSee('Target Video');
        $response->assertSee('This is the video being watched.');
        $response->assertSee('<video', false);
        $response->assertSee('Another Main Channel Video');
        $response->assertSee('Suggested Other Channel Video');
        $response->assertDontSee('Pending Same Channel Video');
        $response->assertSee('Download Original File');
        $this->assertStringContainsString(
            'href="' . $video->videoUrl() . '" download',
            $response->getContent()
        );
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

        $response = $this->actingAs($user)->get('/videos/' . $video->id);

        $response->assertStatus(200);
        $response->assertSee('Video file not available.');
        $response->assertDontSee('<video', false);
        $response->assertDontSee('Download Original File');
    }
}
