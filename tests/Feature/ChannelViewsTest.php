<?php

namespace Tests\Feature;

use App\Models\Channel;
use App\Models\Setting;
use App\Models\User;
use App\Models\Video;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Number;
use Tests\TestCase;

class ChannelViewsTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        $downloadsDir = Setting::getStoragePath();
        foreach ([
            'Size Channel',
            'Delete Files Off Channel',
            'Delete Files On Channel',
            'Delete Files Missing Folder Channel',
        ] as $dir) {
            if (file_exists($downloadsDir . '/' . $dir)) {
                exec('rm -rf ' . escapeshellarg($downloadsDir . '/' . $dir));
            }
        }

        parent::tearDown();
    }

    public function test_quality_selector_moved_from_index_to_channel_show_page()
    {
        $user = User::factory()->create();
        $channel = Channel::create([
            'youtube_id' => 'UC_quality_chan',
            'name' => 'Quality Channel',
            'url' => 'https://example.com/quality',
            'download_quality' => '720p',
        ]);

        $indexResponse = $this->actingAs($user)->get('/channels');
        $indexResponse->assertStatus(200);
        $indexResponse->assertDontSee('name="quality"', false);
        $indexResponse->assertSee('720p');

        $showResponse = $this->actingAs($user)->get('/channels/' . $channel->id);
        $showResponse->assertStatus(200);
        $showResponse->assertSee('name="quality"', false);
        $showResponse->assertSee('Cut-off Date');
        $showResponse->assertSee('Quality');
    }

    public function test_quality_can_still_be_updated_from_the_channel_show_page()
    {
        $user = User::factory()->create();
        $channel = Channel::create([
            'youtube_id' => 'UC_quality_chan2',
            'name' => 'Quality Channel 2',
            'url' => 'https://example.com/quality2',
            'download_quality' => '720p',
        ]);

        $response = $this->actingAs($user)->patch('/channels/' . $channel->id . '/quality', [
            'quality' => '1080p',
        ]);

        $response->assertRedirect();
        $this->assertEquals('1080p', $channel->fresh()->download_quality);
    }

    public function test_delete_files_checkbox_lives_in_a_shared_confirmation_modal_not_on_each_card()
    {
        $user = User::factory()->create();
        Channel::create([
            'youtube_id' => 'UC_modal_chan_a',
            'name' => 'Modal Channel A',
            'url' => 'https://example.com/modala',
        ]);
        Channel::create([
            'youtube_id' => 'UC_modal_chan_b',
            'name' => 'Modal Channel B',
            'url' => 'https://example.com/modalb',
        ]);

        $response = $this->actingAs($user)->get('/channels');

        $response->assertStatus(200);
        $response->assertSee('id="delete-channel-modal"', false);
        $response->assertSee('Also delete downloaded files from disk');

        // Exactly one checkbox for the whole page (the shared modal's), not one per card.
        $this->assertSame(
            1,
            substr_count($response->getContent(), '<input type="checkbox" name="delete_files"')
        );

        // No native confirm() dialog left over from the old inline UX.
        $response->assertDontSee('onsubmit="return confirm(', false);
    }

    public function test_channel_shows_total_downloaded_size_on_index_and_show_pages()
    {
        $user = User::factory()->create();
        $channel = Channel::create([
            'youtube_id' => 'UC_size_chan',
            'name' => 'Size Channel',
            'url' => 'https://example.com/size',
        ]);

        $downloadsDir = Setting::getStoragePath();
        $videoDir = $downloadsDir . '/Size Channel/Season 2026';
        mkdir($videoDir, 0755, true);

        $relativePath1 = 'Size Channel/Season 2026/video-1.mp4';
        $relativePath2 = 'Size Channel/Season 2026/video-2.mp4';
        file_put_contents($downloadsDir . '/' . $relativePath1, str_repeat('a', 1_000_000));
        file_put_contents($downloadsDir . '/' . $relativePath2, str_repeat('b', 500_000));

        Video::create([
            'channel_id' => $channel->id,
            'youtube_id' => 'size_vid_1',
            'title' => 'Size Video 1',
            'published_at' => now(),
            'status' => 'completed',
            'file_path' => $relativePath1,
        ]);

        Video::create([
            'channel_id' => $channel->id,
            'youtube_id' => 'size_vid_2',
            'title' => 'Size Video 2',
            'published_at' => now(),
            'status' => 'completed',
            'file_path' => $relativePath2,
        ]);

        // Pending video without a file on disk must not contribute to the total.
        Video::create([
            'channel_id' => $channel->id,
            'youtube_id' => 'size_vid_pending',
            'title' => 'Size Video Pending',
            'published_at' => now(),
            'status' => 'pending',
        ]);

        $expectedSize = Number::fileSize(1_500_000, precision: 1);

        $indexResponse = $this->actingAs($user)->get('/channels');
        $indexResponse->assertStatus(200);
        $indexResponse->assertSee($expectedSize);

        $showResponse = $this->actingAs($user)->get('/channels/' . $channel->id);
        $showResponse->assertStatus(200);
        $showResponse->assertSee($expectedSize);
    }

    public function test_channels_index_paginates_at_10_per_page()
    {
        $user = User::factory()->create();

        for ($i = 1; $i <= 12; $i++) {
            Channel::create([
                'youtube_id' => 'UC_page_chan_' . $i,
                'name' => 'Page Channel ' . str_pad($i, 2, '0', STR_PAD_LEFT),
                'url' => 'https://example.com/page' . $i,
            ]);
        }

        $page1 = $this->actingAs($user)->get('/channels');
        $page1->assertStatus(200);
        $page1->assertSee('Page Channel 01');
        $page1->assertSee('Page Channel 10');
        $page1->assertDontSee('Page Channel 11');
        $page1->assertSee('Page 1 of 2');

        $page2 = $this->actingAs($user)->get('/channels?page=2');
        $page2->assertStatus(200);
        $page2->assertSee('Page Channel 11');
        $page2->assertSee('Page Channel 12');
    }

    public function test_channel_show_paginates_videos_at_10_per_page()
    {
        $user = User::factory()->create();
        $channel = Channel::create([
            'youtube_id' => 'UC_video_page_chan',
            'name' => 'Video Page Channel',
            'url' => 'https://example.com/videopage',
        ]);

        for ($i = 1; $i <= 12; $i++) {
            Video::create([
                'channel_id' => $channel->id,
                'youtube_id' => 'video_page_vid_' . $i,
                'title' => 'Video Page Item ' . str_pad($i, 2, '0', STR_PAD_LEFT),
                'published_at' => now()->subMinutes($i),
                'status' => 'completed',
            ]);
        }

        $page1 = $this->actingAs($user)->get('/channels/' . $channel->id);
        $page1->assertStatus(200);
        $page1->assertSee('Video Page Item 01');
        $page1->assertSee('Video Page Item 10');
        $page1->assertDontSee('Video Page Item 11');
        $page1->assertSee('Page 1 of 2');

        $page2 = $this->actingAs($user)->get('/channels/' . $channel->id . '?page=2');
        $page2->assertStatus(200);
        $page2->assertSee('Video Page Item 11');
        $page2->assertSee('Video Page Item 12');
    }

    public function test_channel_show_video_sort_by_title()
    {
        $user = User::factory()->create();
        $channel = Channel::create([
            'youtube_id' => 'UC_video_sort_chan',
            'name' => 'Video Sort Channel',
            'url' => 'https://example.com/videosort',
        ]);

        Video::create([
            'channel_id' => $channel->id,
            'youtube_id' => 'video_sort_b',
            'title' => 'Banana video',
            'published_at' => now(),
            'status' => 'completed',
        ]);

        Video::create([
            'channel_id' => $channel->id,
            'youtube_id' => 'video_sort_a',
            'title' => 'Apple video',
            'published_at' => now(),
            'status' => 'completed',
        ]);

        $response = $this->actingAs($user)->get('/channels/' . $channel->id . '?video_sort=title');
        $response->assertStatus(200);

        $content = $response->getContent();
        $this->assertTrue(strpos($content, 'Apple video') < strpos($content, 'Banana video'));
    }

    public function test_invalid_channel_url_shows_error_message_on_redirect_back()
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)
            ->from('/channels')
            ->followingRedirects()
            ->post('/channels', ['url' => 'not-a-valid-url']);

        $response->assertStatus(200);
        $response->assertSee('The url field must be a valid URL.');
    }

    public function test_updating_quality_shows_status_message_on_channel_show_page()
    {
        $user = User::factory()->create();
        $channel = Channel::create([
            'youtube_id' => 'UC_status_chan',
            'name' => 'Status Channel',
            'url' => 'https://example.com/status',
            'download_quality' => '720p',
        ]);

        $response = $this->actingAs($user)
            ->from('/channels/' . $channel->id)
            ->followingRedirects()
            ->patch('/channels/' . $channel->id . '/quality', ['quality' => '1080p']);

        $response->assertStatus(200);
        $response->assertSee('Quality updated successfully!');
        $this->assertEquals('1080p', $channel->fresh()->download_quality);
    }

    public function test_updating_cutoff_shows_status_message_on_channel_show_page()
    {
        $user = User::factory()->create();
        $channel = Channel::create([
            'youtube_id' => 'UC_status_chan2',
            'name' => 'Status Channel 2',
            'url' => 'https://example.com/status2',
            'download_quality' => '720p',
        ]);

        $response = $this->actingAs($user)
            ->from('/channels/' . $channel->id)
            ->followingRedirects()
            ->patch('/channels/' . $channel->id . '/cutoff', ['cutoff_date' => '2026-01-01']);

        $response->assertStatus(200);
        $response->assertSee('Cut-off date updated successfully!');
        $this->assertEquals('2026-01-01', $channel->fresh()->cutoff_date);
    }

    public function test_deleting_channel_without_delete_files_flag_preserves_files_on_disk()
    {
        $user = User::factory()->create();
        $channel = Channel::create([
            'youtube_id' => 'UC_delete_off_chan',
            'name' => 'Delete Files Off Channel',
            'url' => 'https://example.com/deleteoff',
        ]);

        $downloadsDir = Setting::getStoragePath();
        $channelDir = $downloadsDir . '/Delete Files Off Channel';
        mkdir($channelDir, 0755, true);
        $filePath = $channelDir . '/keepme.mp4';
        file_put_contents($filePath, 'video bytes');

        $response = $this->actingAs($user)->delete('/channels/' . $channel->id);

        $response->assertRedirect('/channels');
        $this->assertNull(Channel::find($channel->id));
        $this->assertFileExists($filePath);
    }

    public function test_deleting_channel_with_delete_files_flag_removes_folder_from_disk()
    {
        $user = User::factory()->create();
        $channel = Channel::create([
            'youtube_id' => 'UC_delete_on_chan',
            'name' => 'Delete Files On Channel',
            'url' => 'https://example.com/deleteon',
        ]);

        $downloadsDir = Setting::getStoragePath();
        $channelDir = $downloadsDir . '/Delete Files On Channel';
        mkdir($channelDir, 0755, true);
        file_put_contents($channelDir . '/removeme.mp4', 'video bytes');

        $response = $this->actingAs($user)->delete('/channels/' . $channel->id, ['delete_files' => '1']);

        $response->assertRedirect('/channels');
        $this->assertNull(Channel::find($channel->id));
        $this->assertDirectoryDoesNotExist($channelDir);
    }

    public function test_deleting_channel_with_delete_files_flag_but_no_folder_on_disk_does_not_error()
    {
        $user = User::factory()->create();
        $channel = Channel::create([
            'youtube_id' => 'UC_delete_missing_chan',
            'name' => 'Delete Files Missing Folder Channel',
            'url' => 'https://example.com/deletemissing',
        ]);

        // Note: the channel folder is deliberately never created on disk, so the
        // realpath()-based containment guard in ChannelController must resolve to
        // false and silently skip deletion instead of throwing.
        $response = $this->actingAs($user)->delete('/channels/' . $channel->id, ['delete_files' => '1']);

        $response->assertRedirect('/channels');
        $this->assertNull(Channel::find($channel->id));
    }

    public function test_deleting_channel_with_delete_files_flag_never_deletes_outside_downloads_dir()
    {
        $user = User::factory()->create();

        // PlexNaming::sanitize() only strips filesystem-unsafe characters (/, \, :, etc.),
        // so a channel literally named ".." survives sanitization unchanged and would,
        // without the realpath() containment guard, resolve to the parent of the
        // downloads directory — i.e. an attempted deletion outside the intended tree.
        $channel = Channel::create([
            'youtube_id' => 'UC_delete_traversal_chan',
            'name' => '..',
            'url' => 'https://example.com/deletetraversal',
        ]);

        $downloadsDir = Setting::getStoragePath();
        if (!file_exists($downloadsDir)) {
            mkdir($downloadsDir, 0755, true);
        }
        $parentDir = dirname($downloadsDir);
        $canaryFile = $parentDir . '/canary.txt';
        file_put_contents($canaryFile, 'must survive channel deletion');

        try {
            $response = $this->actingAs($user)->delete('/channels/' . $channel->id, ['delete_files' => '1']);

            $response->assertRedirect('/channels');
            $this->assertNull(Channel::find($channel->id));
            $this->assertDirectoryExists($downloadsDir);
            $this->assertFileExists($canaryFile);
        } finally {
            if (file_exists($canaryFile)) {
                unlink($canaryFile);
            }
        }
    }
}
