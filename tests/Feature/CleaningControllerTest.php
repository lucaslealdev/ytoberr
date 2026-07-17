<?php

namespace Tests\Feature;

use App\Models\Channel;
use App\Models\Setting;
use App\Models\User;
use App\Models\Video;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CleaningControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        $downloadsDir = Setting::getStoragePath();
        if (file_exists($downloadsDir.'/Cleaning Test Channel')) {
            exec('rm -rf '.escapeshellarg($downloadsDir.'/Cleaning Test Channel'));
        }

        parent::tearDown();
    }

    private function makeVideo(string $youtubeId, int $fileSizeBytes, array $overrides = []): Video
    {
        $channel = Channel::firstOrCreate(
            ['youtube_id' => 'UC_cleaning_test_chan'],
            ['name' => 'Cleaning Test Channel', 'url' => 'https://example.com/cleaningtest']
        );

        $downloadsDir = Setting::getStoragePath();
        $videoDir = $downloadsDir.'/Cleaning Test Channel/Season 2026';
        if (! file_exists($videoDir)) {
            mkdir($videoDir, 0755, true);
        }

        $relativeVideoPath = "Cleaning Test Channel/Season 2026/{$youtubeId}.mp4";
        $relativeThumbPath = "Cleaning Test Channel/Season 2026/{$youtubeId}.jpg";
        file_put_contents($downloadsDir.'/'.$relativeVideoPath, str_repeat('a', 10));
        file_put_contents($downloadsDir.'/'.$relativeThumbPath, 'fake thumb bytes');

        return Video::create(array_merge([
            'channel_id' => $channel->id,
            'youtube_id' => $youtubeId,
            'title' => 'Cleaning Test Video '.$youtubeId,
            'published_at' => now(),
            'status' => 'completed',
            'file_path' => $relativeVideoPath,
            'file_size' => $fileSizeBytes,
            'thumbnail_path' => $relativeThumbPath,
            'downloaded_at' => now(),
        ], $overrides));
    }

    public function test_cleaning_page_requires_advanced_mode()
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get('/cleaning');

        $response->assertRedirect('/settings');
    }

    public function test_cleaning_page_shows_at_most_ten_videos_ordered_by_size_descending()
    {
        Setting::set('advanced_mode', '1');
        $user = User::factory()->create();

        for ($i = 1; $i <= 12; $i++) {
            $this->makeVideo("cleaning_vid_{$i}", $i * 1000);
        }

        $response = $this->actingAs($user)->get('/cleaning');

        $response->assertStatus(200);
        $response->assertSee('Cleaning Test Video cleaning_vid_12');
        $response->assertDontSee('Cleaning Test Video cleaning_vid_2');

        $content = $response->getContent();
        $this->assertTrue(
            strpos($content, 'cleaning_vid_12') < strpos($content, 'cleaning_vid_11'),
            'Heaviest video should be listed first.'
        );
    }

    public function test_cleaning_page_shows_a_select_all_checkbox_and_a_checkbox_per_video()
    {
        Setting::set('advanced_mode', '1');
        $user = User::factory()->create();

        $this->makeVideo('cleaning_checkbox_vid', 5000);

        $response = $this->actingAs($user)->get('/cleaning');

        $response->assertStatus(200);
        $response->assertSee('id="cleaning-select-all"', false);
        $response->assertSee('cleaning-video-checkbox', false);
        $response->assertSee('id="cleaning-delete-modal"', false);
    }

    public function test_bulk_delete_without_flags_removes_selected_videos_entirely_and_leaves_files()
    {
        Setting::set('advanced_mode', '1');
        $user = User::factory()->create();

        $videoA = $this->makeVideo('cleaning_bulk_a', 1000);
        $videoB = $this->makeVideo('cleaning_bulk_b', 2000);
        $downloadsDir = Setting::getStoragePath();
        $pathA = $downloadsDir.'/'.$videoA->file_path;
        $pathB = $downloadsDir.'/'.$videoB->file_path;

        $response = $this->actingAs($user)->delete('/cleaning/videos', [
            'video_ids' => [$videoA->id, $videoB->id],
        ]);

        $response->assertRedirect(route('cleaning.index'));
        $this->assertNull(Video::find($videoA->id));
        $this->assertNull(Video::find($videoB->id));
        $this->assertFileExists($pathA);
        $this->assertFileExists($pathB);
    }

    public function test_bulk_delete_with_delete_files_flag_removes_files_from_disk()
    {
        Setting::set('advanced_mode', '1');
        $user = User::factory()->create();

        $video = $this->makeVideo('cleaning_bulk_files', 3000);
        $downloadsDir = Setting::getStoragePath();
        $videoPath = $downloadsDir.'/'.$video->file_path;
        $thumbPath = $downloadsDir.'/'.$video->thumbnail_path;

        $response = $this->actingAs($user)->delete('/cleaning/videos', [
            'video_ids' => [$video->id],
            'delete_files' => '1',
        ]);

        $response->assertRedirect(route('cleaning.index'));
        $this->assertNull(Video::find($video->id));
        $this->assertFileDoesNotExist($videoPath);
        $this->assertFileDoesNotExist($thumbPath);
    }

    public function test_bulk_delete_with_prevent_redownload_flag_keeps_hidden_blacklist_rows()
    {
        Setting::set('advanced_mode', '1');
        $user = User::factory()->create();

        $video = $this->makeVideo('cleaning_bulk_blacklist', 4000);

        $response = $this->actingAs($user)->delete('/cleaning/videos', [
            'video_ids' => [$video->id],
            'delete_files' => '1',
            'prevent_redownload' => '1',
        ]);

        $response->assertRedirect(route('cleaning.index'));

        $video->refresh();
        $this->assertSame('deleted', $video->status);
        $this->assertNull($video->file_path);
        $this->assertNull($video->file_size);
        $this->assertTrue((bool) $video->prevent_download);
    }

    public function test_bulk_delete_requires_at_least_one_video_id()
    {
        Setting::set('advanced_mode', '1');
        $user = User::factory()->create();

        $response = $this->actingAs($user)->delete('/cleaning/videos', []);

        $response->assertSessionHasErrors('video_ids');
    }

    public function test_bulk_delete_requires_advanced_mode()
    {
        $user = User::factory()->create();
        $video = $this->makeVideo('cleaning_no_advanced_mode', 5000);

        $response = $this->actingAs($user)->delete('/cleaning/videos', [
            'video_ids' => [$video->id],
        ]);

        $response->assertRedirect('/settings');
        $this->assertNotNull(Video::find($video->id));
    }

    public function test_sidebar_shows_cleaning_link_only_when_advanced_mode_is_enabled()
    {
        $user = User::factory()->create();

        $withoutAdvanced = $this->actingAs($user)->get('/channels');
        $withoutAdvanced->assertDontSee('🧹 Cleaning', false);

        Setting::set('advanced_mode', '1');

        $withAdvanced = $this->actingAs($user)->get('/channels');
        $withAdvanced->assertSee('🧹 Cleaning', false);
    }
}
