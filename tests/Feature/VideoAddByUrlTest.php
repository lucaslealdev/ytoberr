<?php

namespace Tests\Feature;

use App\Models\Channel;
use App\Models\User;
use App\Models\Video;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class VideoAddByUrlTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Creates a fake yt-dlp executable that answers the full JSON dump (-J) call made by
     * VideoController::store() with a single-video payload, regardless of the URL/args it's
     * invoked with. As with ChannelViewsTest's equivalent helper, this also transparently
     * satisfies the other yt-dlp invocations that happen synchronously afterwards in the same
     * request (ChannelService's image fetch) — that call fails to find its expected output
     * and just logs a warning instead of throwing, so one unconditional response is enough.
     *
     * @param  array<string, mixed>  $overrides
     */
    private function mockYtDlpVideoMetadata(string $name, array $overrides = []): string
    {
        $mockYtDlp = storage_path("app/temp/mock_ytdlp_{$name}.sh");

        $metadataJson = json_encode(array_merge([
            '_type' => 'video',
            'id' => 'not_due_vid',
            'title' => 'A Great Video',
            'description' => 'A description of the video.',
            'duration' => 300,
            'timestamp' => 1700000000,
            'upload_date' => '20231114',
            'channel' => 'Some Channel',
            'channel_id' => 'UC_some_channel',
            'channel_url' => 'https://www.youtube.com/channel/UC_some_channel',
            'uploader' => 'Some Channel',
            'uploader_url' => 'https://www.youtube.com/@somechannel',
        ], $overrides));

        $script = <<<'BASH'
#!/bin/bash
echo '__METADATA__'
exit 0
BASH;

        if (! is_dir(dirname($mockYtDlp))) {
            mkdir(dirname($mockYtDlp), 0755, true);
        }

        file_put_contents($mockYtDlp, str_replace('__METADATA__', $metadataJson, $script));
        chmod($mockYtDlp, 0755);

        return $mockYtDlp;
    }

    public function test_videos_page_shows_the_add_video_form()
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get('/videos');

        $response->assertStatus(200);
        $response->assertSee('id="add-video-form"', false);
        $response->assertSee('name="quality"', false);
    }

    public function test_adding_a_video_by_url_registers_a_new_channel_at_the_chosen_quality_and_queues_it()
    {
        Storage::fake('public');
        $user = User::factory()->create();

        $mockYtDlp = $this->mockYtDlpVideoMetadata('new_channel_video');
        config(['services.ytdlp_path' => $mockYtDlp]);

        $response = $this->actingAs($user)->post('/videos', [
            'url' => 'https://www.youtube.com/watch?v=not_due_vid',
            'quality' => '1080p',
        ]);

        $response->assertRedirect('/videos');
        $response->assertSessionHasNoErrors();

        $channel = Channel::where('youtube_id', 'UC_some_channel')->first();
        $this->assertNotNull($channel);
        $this->assertSame('Some Channel', $channel->name);
        $this->assertSame('1080p', $channel->download_quality);

        $video = Video::where('youtube_id', 'not_due_vid')->first();
        $this->assertNotNull($video);
        $this->assertSame($channel->id, $video->channel_id);
        $this->assertSame('A Great Video', $video->title);
        $this->assertSame('pending', $video->status);
        $this->assertSame(300, $video->duration);

        unlink($mockYtDlp);
    }

    public function test_adding_a_video_from_an_already_known_channel_reuses_it_and_updates_its_quality()
    {
        $user = User::factory()->create();
        $channel = Channel::create([
            'youtube_id' => 'UC_some_channel',
            'name' => 'Some Channel',
            'url' => 'https://www.youtube.com/channel/UC_some_channel',
            'download_quality' => '480p',
        ]);

        $mockYtDlp = $this->mockYtDlpVideoMetadata('existing_channel_video');
        config(['services.ytdlp_path' => $mockYtDlp]);

        $response = $this->actingAs($user)->post('/videos', [
            'url' => 'https://www.youtube.com/watch?v=not_due_vid',
            'quality' => '1080p',
        ]);

        $response->assertRedirect('/videos');
        $response->assertSessionHasNoErrors();

        $this->assertSame(1, Channel::count());
        $channel->refresh();
        $this->assertSame('1080p', $channel->download_quality);

        $video = Video::where('youtube_id', 'not_due_vid')->first();
        $this->assertNotNull($video);
        $this->assertSame($channel->id, $video->channel_id);

        unlink($mockYtDlp);
    }

    public function test_adding_a_video_that_already_exists_is_rejected()
    {
        $user = User::factory()->create();
        $channel = Channel::create([
            'youtube_id' => 'UC_some_channel',
            'name' => 'Some Channel',
            'url' => 'https://www.youtube.com/channel/UC_some_channel',
        ]);
        Video::create([
            'channel_id' => $channel->id,
            'youtube_id' => 'not_due_vid',
            'title' => 'Already Known Video',
            'published_at' => now(),
            'status' => 'completed',
        ]);

        $mockYtDlp = $this->mockYtDlpVideoMetadata('duplicate_video');
        config(['services.ytdlp_path' => $mockYtDlp]);

        $response = $this->actingAs($user)
            ->from('/videos')
            ->followingRedirects()
            ->post('/videos', [
                'url' => 'https://www.youtube.com/watch?v=not_due_vid',
                'quality' => '720p',
            ]);

        $response->assertStatus(200);
        $response->assertSee('This video has already been added.');
        $this->assertSame(1, Video::where('youtube_id', 'not_due_vid')->count());

        unlink($mockYtDlp);
    }

    public function test_rejects_a_url_yt_dlp_cannot_resolve_to_a_single_video()
    {
        $user = User::factory()->create();

        $mockYtDlp = $this->mockYtDlpVideoMetadata('not_a_video', ['_type' => 'playlist', 'id' => null]);
        config(['services.ytdlp_path' => $mockYtDlp]);

        $response = $this->actingAs($user)
            ->from('/videos')
            ->followingRedirects()
            ->post('/videos', [
                'url' => 'https://www.youtube.com/playlist?list=some_playlist',
                'quality' => '720p',
            ]);

        $response->assertStatus(200);
        $response->assertSee('Make sure this is a single video URL.');
        $this->assertSame(0, Video::count());

        unlink($mockYtDlp);
    }

    public function test_rejects_an_invalid_url()
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)
            ->from('/videos')
            ->followingRedirects()
            ->post('/videos', ['url' => 'not-a-valid-url', 'quality' => '720p']);

        $response->assertStatus(200);
        $response->assertSee('The url field must be a valid URL.');
    }

    public function test_rejects_an_invalid_quality()
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->post('/videos', [
            'url' => 'https://www.youtube.com/watch?v=not_due_vid',
            'quality' => '4k',
        ]);

        $response->assertSessionHasErrors('quality');
        $this->assertSame(0, Video::count());
    }
}
