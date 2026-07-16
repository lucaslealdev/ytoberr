<?php

namespace Tests\Feature;

use App\Models\Channel;
use App\Models\Setting;
use App\Models\User;
use App\Models\Video;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class VideoDeleteTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        $downloadsDir = Setting::getStoragePath();
        foreach (['Delete Test Channel', 'Delete Traversal Channel'] as $dir) {
            if (file_exists($downloadsDir.'/'.$dir)) {
                exec('rm -rf '.escapeshellarg($downloadsDir.'/'.$dir));
            }
        }

        parent::tearDown();
    }

    private function makeChannelAndVideo(string $youtubeId, array $overrides = []): array
    {
        $channel = Channel::create([
            'youtube_id' => 'UC_delete_test_chan',
            'name' => 'Delete Test Channel',
            'url' => 'https://example.com/deletetest',
        ]);

        $downloadsDir = Setting::getStoragePath();
        $videoDir = $downloadsDir.'/Delete Test Channel/Season 2026';
        mkdir($videoDir, 0755, true);

        $relativeVideoPath = "Delete Test Channel/Season 2026/{$youtubeId}.mp4";
        $relativeThumbPath = "Delete Test Channel/Season 2026/{$youtubeId}.jpg";
        $relativeNfoPath = "Delete Test Channel/Season 2026/{$youtubeId}.nfo";
        file_put_contents($downloadsDir.'/'.$relativeVideoPath, 'fake video bytes');
        file_put_contents($downloadsDir.'/'.$relativeThumbPath, 'fake thumb bytes');
        file_put_contents($downloadsDir.'/'.$relativeNfoPath, '<episodedetails></episodedetails>');

        $video = Video::create(array_merge([
            'channel_id' => $channel->id,
            'youtube_id' => $youtubeId,
            'title' => 'Delete Test Video',
            'published_at' => now(),
            'status' => 'completed',
            'file_path' => $relativeVideoPath,
            'file_size' => 123,
            'thumbnail_path' => $relativeThumbPath,
            'downloaded_at' => now(),
        ], $overrides));

        return [$channel, $video, $downloadsDir];
    }

    public function test_video_actions_kebab_and_shared_modal_appear_on_show_index_and_channel_pages()
    {
        $user = User::factory()->create();
        [$channel, $video] = $this->makeChannelAndVideo('kebab_vid');

        $showResponse = $this->actingAs($user)->get('/videos/'.$video->id);
        $showResponse->assertStatus(200);
        $showResponse->assertSee('data-video-id="'.$video->id.'"', false);
        $this->assertSame(1, substr_count($showResponse->getContent(), 'id="delete-video-modal"'));

        $indexResponse = $this->actingAs($user)->get('/videos');
        $indexResponse->assertStatus(200);
        $indexResponse->assertSee('data-video-id="'.$video->id.'"', false);
        $this->assertSame(1, substr_count($indexResponse->getContent(), 'id="delete-video-modal"'));

        $channelResponse = $this->actingAs($user)->get('/channels/'.$channel->id);
        $channelResponse->assertStatus(200);
        $channelResponse->assertSee('data-video-id="'.$video->id.'"', false);
        $this->assertSame(1, substr_count($channelResponse->getContent(), 'id="delete-video-modal"'));
    }

    public function test_deleting_a_video_without_any_flags_removes_it_entirely_and_leaves_files_on_disk()
    {
        $user = User::factory()->create();
        [, $video, $downloadsDir] = $this->makeChannelAndVideo('hard_delete_vid');
        $videoPath = $downloadsDir.'/'.$video->file_path;

        $response = $this->actingAs($user)->delete('/videos/'.$video->id);

        $response->assertRedirect();
        $this->assertNull(Video::find($video->id));
        $this->assertFileExists($videoPath);
    }

    public function test_deleting_a_video_with_delete_files_flag_removes_video_thumbnail_and_nfo_from_disk()
    {
        $user = User::factory()->create();
        [, $video, $downloadsDir] = $this->makeChannelAndVideo('delete_files_vid');
        $videoPath = $downloadsDir.'/'.$video->file_path;
        $thumbPath = $downloadsDir.'/'.$video->thumbnail_path;
        $nfoPath = $downloadsDir.'/Delete Test Channel/Season 2026/delete_files_vid.nfo';

        $response = $this->actingAs($user)->delete('/videos/'.$video->id, ['delete_files' => '1']);

        $response->assertRedirect();
        $this->assertNull(Video::find($video->id));
        $this->assertFileDoesNotExist($videoPath);
        $this->assertFileDoesNotExist($thumbPath);
        $this->assertFileDoesNotExist($nfoPath);
    }

    public function test_deleting_a_video_with_delete_files_flag_never_deletes_outside_downloads_dir()
    {
        $user = User::factory()->create();
        $channel = Channel::create([
            'youtube_id' => 'UC_delete_traversal_chan',
            'name' => 'Delete Traversal Channel',
            'url' => 'https://example.com/deletetraversal',
        ]);

        $downloadsDir = Setting::getStoragePath();
        if (! file_exists($downloadsDir)) {
            mkdir($downloadsDir, 0755, true);
        }
        $parentDir = dirname($downloadsDir);
        $canaryFile = $parentDir.'/canary.txt';
        file_put_contents($canaryFile, 'must survive video deletion');

        // A file_path that escapes the downloads directory (as if crafted or corrupted) must
        // never resolve outside it, mirroring ChannelController's own containment guard.
        $video = Video::create([
            'channel_id' => $channel->id,
            'youtube_id' => 'traversal_vid',
            'title' => 'Traversal Video',
            'published_at' => now(),
            'status' => 'completed',
            'file_path' => '../canary.txt',
        ]);

        try {
            $response = $this->actingAs($user)->delete('/videos/'.$video->id, ['delete_files' => '1']);

            $response->assertRedirect();
            $this->assertNull(Video::find($video->id));
            $this->assertFileExists($canaryFile);
        } finally {
            if (file_exists($canaryFile)) {
                unlink($canaryFile);
            }
        }
    }

    public function test_deleting_a_video_with_prevent_redownload_flag_keeps_a_hidden_blacklist_row()
    {
        $user = User::factory()->create();
        [$channel, $video, $downloadsDir] = $this->makeChannelAndVideo('blacklist_vid');

        $response = $this->actingAs($user)->delete('/videos/'.$video->id, [
            'delete_files' => '1',
            'prevent_redownload' => '1',
        ]);

        $response->assertRedirect();

        $video->refresh();
        $this->assertSame('deleted', $video->status);
        $this->assertNull($video->file_path);
        $this->assertNull($video->file_size);
        $this->assertNull($video->thumbnail_path);
        $this->assertNull($video->downloaded_at);
        $this->assertTrue((bool) $video->prevent_download);

        // Consume the delete's own flash message first — it echoes the video's title, which
        // would otherwise make the assertDontSee() checks below fail for an unrelated reason.
        $this->actingAs($user)->get('/');

        // Invisible everywhere: index, channel page, and a direct link all act as if it
        // doesn't exist.
        $this->actingAs($user)->get('/videos')->assertDontSee($video->title);
        $this->actingAs($user)->get('/channels/'.$channel->id)->assertDontSee($video->title);
        $this->actingAs($user)->get('/videos/'.$video->id)->assertStatus(404);
    }

    public function test_deleting_the_currently_viewed_video_redirects_to_its_channel_page()
    {
        $user = User::factory()->create();
        [$channel, $video] = $this->makeChannelAndVideo('redirect_vid');

        $response = $this->actingAs($user)->from('/videos/'.$video->id)
            ->delete('/videos/'.$video->id, ['redirect_to' => '/channels/'.$channel->id]);

        $response->assertRedirect('/channels/'.$channel->id);
    }

    public function test_deleting_a_video_ignores_an_absolute_or_protocol_relative_redirect_to_value()
    {
        $user = User::factory()->create();
        [, $video] = $this->makeChannelAndVideo('open_redirect_vid');

        $response = $this->actingAs($user)->from('/videos')
            ->delete('/videos/'.$video->id, ['redirect_to' => '//evil.example.com']);

        // Falls back to the normal back()-to-referer behavior instead of honoring an
        // external-looking redirect target.
        $response->assertRedirect('/videos');
    }
}
