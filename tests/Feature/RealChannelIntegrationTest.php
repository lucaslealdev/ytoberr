<?php

namespace Tests\Feature;

use App\Jobs\CheckChannelForNewVideosJob;
use App\Models\Channel;
use App\Models\User;
use App\Models\Video;
use App\Services\ChannelService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class RealChannelIntegrationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Force sqlite
        config(['database.default' => 'sqlite']);
        config(['database.connections.sqlite.database' => ':memory:']);

        $this->artisan('migrate');
        $user = User::factory()->create();
        $this->actingAs($user);
    }

    public function test_can_add_channel_with_real_ytdlp()
    {
        // $this->withoutMiddleware(); // CSRF is not an issue if we just POST.

        $response = $this->post('/channels', [
            'url' => 'https://www.youtube.com/@jiranha',
        ]);

        $response->assertStatus(302);
        $response->assertRedirect('/channels');

        // Assert it exists in DB with the correct channel_id fetched from YT
        $this->assertDatabaseHas('channels', [
            'youtube_id' => 'UCexaxaj4QzEirjgmPCBdhSg',
        ]);
    }

    public function test_creating_a_channel_dispatches_check_channel_for_new_videos_job()
    {
        Queue::fake();

        $response = $this->post('/channels', [
            'url' => 'https://www.youtube.com/@jiranha',
        ]);

        $response->assertStatus(302);

        $channel = Channel::where('youtube_id', 'UCexaxaj4QzEirjgmPCBdhSg')->first();
        $this->assertNotNull($channel);

        Queue::assertPushed(CheckChannelForNewVideosJob::class, function (CheckChannelForNewVideosJob $job) use ($channel) {
            return $job->channel->is($channel);
        });
    }

    public function test_can_download_channel_profile_image()
    {
        Storage::fake('public');

        $response = $this->post('/channels', [
            'url' => 'https://www.youtube.com/@jiranha',
        ]);

        $response->assertStatus(302);

        $channel = Channel::where('youtube_id', 'UCexaxaj4QzEirjgmPCBdhSg')->first();
        $this->assertNotNull($channel);

        // This test might fail because I moved the image storage location
        // Let's assert against the new expected location
        $this->assertNotNull($channel->profile_image_path);

        Storage::disk('public')->assertExists($channel->profile_image_path);
    }

    public function test_can_fetch_and_store_all_channel_images()
    {
        // Use a real channel URL
        $url = 'https://www.youtube.com/@jiranha';

        $channel = Channel::create([
            'youtube_id' => 'UCexaxaj4QzEirjgmPCBdhSg',
            'name' => 'Jiranha',
            'url' => $url, // Assuming URL field exists in Channel model, or just use it in service
            'download_quality' => '720p',
        ]);

        // Ensure the directory doesn't exist yet
        $channelDir = storage_path('app/public/channels/'.$channel->id);
        if (file_exists($channelDir)) {
            exec('rm -rf '.escapeshellarg($channelDir));
        }

        app(ChannelService::class)->fetchAndStoreChannelImages($channel);

        $this->assertTrue(Storage::disk('public')->exists('channels/'.$channel->id.'/poster.jpg'), 'File not found: '.'channels/'.$channel->id.'/poster.jpg'.'. Found: '.implode(', ', Storage::disk('public')->allFiles('channels')));
    }

    public function test_can_view_channel_details_page()
    {
        $channel = Channel::create([
            'youtube_id' => 'UCexaxaj4QzEirjgmPCBdhSg',
            'name' => 'Jiranha',
            'url' => 'https://www.youtube.com/@jiranha',
            'download_quality' => '720p',
        ]);

        $video = Video::create([
            'channel_id' => $channel->id,
            'youtube_id' => 'video_1',
            'title' => 'Test Video Jiranha',
            'published_at' => now(),
            'status' => 'completed',
        ]);

        $response = $this->get('/channels/'.$channel->id);

        $response->assertStatus(200);
        $response->assertSee('Jiranha');
        $response->assertSee('Test Video Jiranha');
    }

    public function test_channel_cutoff_date_defaults_to_today_and_can_be_updated()
    {
        // 1. Assert default cutoff_date is today
        $channel = Channel::create([
            'youtube_id' => 'UC_test_cutoff',
            'name' => 'Cutoff Channel',
            'url' => 'https://example.com/test',
            'download_quality' => '720p',
        ]);

        $this->assertEquals(now()->toDateString(), $channel->cutoff_date);

        // 2. Assert we can update the cutoff_date
        $response = $this->patch("/channels/{$channel->id}/cutoff", [
            'cutoff_date' => '2026-01-01',
        ]);

        $response->assertStatus(302); // Redirect back
        $channel->refresh();
        $this->assertEquals('2026-01-01', $channel->cutoff_date);
    }
}
