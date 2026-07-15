<?php

namespace Tests\Feature;

use App\Models\Channel;
use App\Models\Setting;
use App\Models\User;
use App\Models\Video;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
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
            if (file_exists($downloadsDir.'/'.$dir)) {
                exec('rm -rf '.escapeshellarg($downloadsDir.'/'.$dir));
            }
        }

        parent::tearDown();
    }

    public function test_quality_can_still_be_updated_from_the_channel_show_page()
    {
        $user = User::factory()->create();
        $channel = Channel::create([
            'youtube_id' => 'UC_quality_chan2',
            'name' => 'Quality Channel 2',
            'url' => 'https://example.com/quality2',
            'download_quality' => '720p',
            'cutoff_date' => '2026-01-01',
        ]);

        $response = $this->actingAs($user)->patch('/channels/'.$channel->id.'/settings', [
            'quality' => '1080p',
            'cutoff_date' => $channel->cutoff_date,
        ]);

        $response->assertRedirect();
        $this->assertEquals('1080p', $channel->fresh()->download_quality);
    }

    public function test_download_shorts_defaults_to_off_and_toggle_lives_in_channel_settings_modal()
    {
        $user = User::factory()->create();
        $channel = Channel::create([
            'youtube_id' => 'UC_shorts_toggle_chan',
            'name' => 'Shorts Toggle Channel',
            'url' => 'https://example.com/shortstoggle',
        ]);

        $this->assertFalse((bool) $channel->fresh()->download_shorts);

        $response = $this->actingAs($user)->get('/channels/'.$channel->id);
        $response->assertStatus(200);
        $response->assertSee('name="download_shorts"', false);
        $response->assertSee('Download Shorts');

        // The shared settings modal's checkbox is always static/unchecked in the markup — its
        // real per-channel state is read from the card's data-download-shorts attribute and
        // applied by JS when the modal opens, so that's what must reflect the true value.
        $response->assertSee('data-download-shorts="0"', false);
        $this->assertDoesNotMatchRegularExpression(
            '/name="download_shorts"[^>]*checked/',
            $response->getContent()
        );
    }

    public function test_channel_actions_card_data_attributes_reflect_true_channel_settings()
    {
        $user = User::factory()->create();
        $channel = Channel::create([
            'youtube_id' => 'UC_data_attrs_chan',
            'name' => 'Data Attrs Channel',
            'url' => 'https://example.com/dataattrs',
            'download_quality' => '1080p',
            'cutoff_date' => '2026-02-01',
            'download_shorts' => true,
        ]);

        $response = $this->actingAs($user)->get('/channels/'.$channel->id);

        $response->assertStatus(200);
        $response->assertSee('data-channel-id="'.$channel->id.'"', false);
        $response->assertSee('data-quality="1080p"', false);
        $response->assertSee('data-cutoff-date="2026-02-01"', false);
        $response->assertSee('data-download-shorts="1"', false);
    }

    public function test_channels_index_shows_channel_actions_kebab_per_card_with_a_single_shared_modal()
    {
        $user = User::factory()->create();
        $channelA = Channel::create([
            'youtube_id' => 'UC_index_actions_a',
            'name' => 'Index Actions Channel A',
            'url' => 'https://example.com/indexactionsa',
            'download_quality' => '480p',
        ]);
        $channelB = Channel::create([
            'youtube_id' => 'UC_index_actions_b',
            'name' => 'Index Actions Channel B',
            'url' => 'https://example.com/indexactionsb',
            'download_quality' => '1080p',
        ]);

        $response = $this->actingAs($user)->get('/channels');
        $response->assertStatus(200);

        $content = $response->getContent();

        // Each card carries its own channel-specific data-attributes...
        $response->assertSee('data-channel-id="'.$channelA->id.'"', false);
        $response->assertSee('data-channel-id="'.$channelB->id.'"', false);
        $response->assertSee('data-quality="480p"', false);
        $response->assertSee('data-quality="1080p"', false);

        // ...but the settings/delete modals are rendered exactly once for the whole page,
        // not duplicated per card.
        $this->assertSame(1, substr_count($content, 'id="channel-settings-modal"'));
        $this->assertSame(1, substr_count($content, 'id="delete-channel-modal"'));
    }

    public function test_channel_settings_update_returns_json_for_async_requests()
    {
        $user = User::factory()->create();
        $channel = Channel::create([
            'youtube_id' => 'UC_json_settings_chan',
            'name' => 'Json Settings Channel',
            'url' => 'https://example.com/jsonsettings',
            'download_quality' => '720p',
        ]);

        $response = $this->actingAs($user)->patchJson('/channels/'.$channel->id.'/settings', [
            'quality' => '1080p',
            'cutoff_date' => '2026-03-01',
            'download_shorts' => '1',
        ]);

        $response->assertStatus(200);
        $response->assertJson([
            'channel' => [
                'id' => $channel->id,
                'cutoff_date' => '2026-03-01',
                'download_quality' => '1080p',
                'download_shorts' => true,
            ],
        ]);

        $channel->refresh();
        $this->assertEquals('1080p', $channel->download_quality);
        $this->assertEquals('2026-03-01', $channel->cutoff_date);
        $this->assertTrue((bool) $channel->download_shorts);
    }

    public function test_download_shorts_preference_can_be_updated_from_the_channel_show_page()
    {
        $user = User::factory()->create();
        $channel = Channel::create([
            'youtube_id' => 'UC_shorts_update_chan',
            'name' => 'Shorts Update Channel',
            'url' => 'https://example.com/shortsupdate',
            'download_quality' => '720p',
            'cutoff_date' => '2026-01-01',
        ]);

        $settingsPayload = ['quality' => '720p', 'cutoff_date' => '2026-01-01'];

        $response = $this->actingAs($user)->patch('/channels/'.$channel->id.'/settings', $settingsPayload + [
            'download_shorts' => '1',
        ]);

        $response->assertRedirect();
        $this->assertTrue((bool) $channel->fresh()->download_shorts);

        // Unchecking sends no field at all; the controller must treat absence as false.
        $response = $this->actingAs($user)->patch('/channels/'.$channel->id.'/settings', $settingsPayload);

        $response->assertRedirect();
        $this->assertFalse((bool) $channel->fresh()->download_shorts);
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
        $videoDir = $downloadsDir.'/Size Channel/Season 2026';
        mkdir($videoDir, 0755, true);

        $relativePath1 = 'Size Channel/Season 2026/video-1.mp4';
        $relativePath2 = 'Size Channel/Season 2026/video-2.mp4';
        file_put_contents($downloadsDir.'/'.$relativePath1, str_repeat('a', 1_000_000));
        file_put_contents($downloadsDir.'/'.$relativePath2, str_repeat('b', 500_000));

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

        $showResponse = $this->actingAs($user)->get('/channels/'.$channel->id);
        $showResponse->assertStatus(200);
        $showResponse->assertSee($expectedSize);
    }

    public function test_channels_index_search_returns_only_matching_channels()
    {
        $user = User::factory()->create();
        Channel::create([
            'youtube_id' => 'UC_search_match_a',
            'name' => 'Cooking With Anna',
            'url' => 'https://example.com/searcha',
        ]);
        Channel::create([
            'youtube_id' => 'UC_search_match_b',
            'name' => 'Cooking With Ben',
            'url' => 'https://example.com/searchb',
        ]);
        Channel::create([
            'youtube_id' => 'UC_search_nomatch',
            'name' => 'Woodworking Basics',
            'url' => 'https://example.com/searchc',
        ]);

        $response = $this->actingAs($user)->get('/channels?search=cooking');

        $response->assertStatus(200);
        $response->assertSee('Cooking With Anna');
        $response->assertSee('Cooking With Ben');
        $response->assertDontSee('Woodworking Basics');
    }

    public function test_channels_index_search_composes_with_sort_options()
    {
        $user = User::factory()->create();
        Channel::create([
            'youtube_id' => 'UC_search_sort_b',
            'name' => 'Search Sort Banana',
            'url' => 'https://example.com/searchsortb',
        ]);
        Channel::create([
            'youtube_id' => 'UC_search_sort_a',
            'name' => 'Search Sort Apple',
            'url' => 'https://example.com/searchsorta',
        ]);
        Channel::create([
            'youtube_id' => 'UC_search_sort_excluded',
            'name' => 'Unrelated Channel',
            'url' => 'https://example.com/searchsortexcluded',
        ]);

        $response = $this->actingAs($user)->get('/channels?search=Search+Sort&sort=name');

        $response->assertStatus(200);
        $response->assertDontSee('Unrelated Channel');

        $content = $response->getContent();
        $this->assertTrue(strpos($content, 'Search Sort Apple') < strpos($content, 'Search Sort Banana'));
    }

    public function test_channels_index_search_matches_youtube_id()
    {
        $user = User::factory()->create();
        $channel = Channel::create([
            'youtube_id' => 'UC_unique_id_token',
            'name' => 'Channel Without Matching Name',
            'url' => 'https://example.com/youtubeidsearch',
        ]);
        Channel::create([
            'youtube_id' => 'UC_other_channel',
            'name' => 'Other Channel',
            'url' => 'https://example.com/otherchannel',
        ]);

        $response = $this->actingAs($user)->get('/channels?search=unique_id_token');

        $response->assertStatus(200);
        $response->assertSee($channel->name);
        $response->assertDontSee('Other Channel');
    }

    public function test_channels_index_no_match_search_shows_empty_state_without_erroring()
    {
        $user = User::factory()->create();
        Channel::create([
            'youtube_id' => 'UC_empty_state_chan',
            'name' => 'Some Channel',
            'url' => 'https://example.com/emptystate',
        ]);

        $response = $this->actingAs($user)->get('/channels?search=NoChannelMatchesThisTerm');

        $response->assertStatus(200);
        $response->assertSee('No channels found for');
        $response->assertSee('NoChannelMatchesThisTerm');
        $response->assertDontSee('Some Channel');
    }

    public function test_channels_index_with_no_channels_at_all_shows_generic_empty_state()
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get('/channels');

        $response->assertStatus(200);
        $response->assertSee('No channels registered yet.');
    }

    public function test_channels_index_paginates_at_10_per_page()
    {
        $user = User::factory()->create();

        for ($i = 1; $i <= 12; $i++) {
            Channel::create([
                'youtube_id' => 'UC_page_chan_'.$i,
                'name' => 'Page Channel '.str_pad($i, 2, '0', STR_PAD_LEFT),
                'url' => 'https://example.com/page'.$i,
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
                'youtube_id' => 'video_page_vid_'.$i,
                'title' => 'Video Page Item '.str_pad($i, 2, '0', STR_PAD_LEFT),
                'published_at' => now()->subMinutes($i),
                'status' => 'completed',
            ]);
        }

        $page1 = $this->actingAs($user)->get('/channels/'.$channel->id);
        $page1->assertStatus(200);
        $page1->assertSee('Video Page Item 01');
        $page1->assertSee('Video Page Item 10');
        $page1->assertDontSee('Video Page Item 11');
        $page1->assertSee('Page 1 of 2');

        $page2 = $this->actingAs($user)->get('/channels/'.$channel->id.'?page=2');
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

        $response = $this->actingAs($user)->get('/channels/'.$channel->id.'?video_sort=title');
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

    public function test_updating_channel_settings_shows_a_single_status_message_on_the_show_page()
    {
        $user = User::factory()->create();
        $channel = Channel::create([
            'youtube_id' => 'UC_status_chan',
            'name' => 'Status Channel',
            'url' => 'https://example.com/status',
            'download_quality' => '720p',
        ]);

        $response = $this->actingAs($user)
            ->from('/channels/'.$channel->id)
            ->followingRedirects()
            ->patch('/channels/'.$channel->id.'/settings', [
                'quality' => '1080p',
                'cutoff_date' => '2026-01-01',
                'download_shorts' => '1',
            ]);

        $response->assertStatus(200);
        $response->assertSee('Channel settings updated successfully!');

        $channel->refresh();
        $this->assertEquals('1080p', $channel->download_quality);
        $this->assertEquals('2026-01-01', $channel->cutoff_date);
        $this->assertTrue((bool) $channel->download_shorts);
    }

    public function test_deleting_channel_without_delete_files_flag_preserves_files_on_disk()
    {
        Storage::fake('public');

        $user = User::factory()->create();
        $channel = Channel::create([
            'youtube_id' => 'UC_delete_off_chan',
            'name' => 'Delete Files Off Channel',
            'url' => 'https://example.com/deleteoff',
        ]);

        $downloadsDir = Setting::getStoragePath();
        $channelDir = $downloadsDir.'/Delete Files Off Channel';
        mkdir($channelDir, 0755, true);
        $filePath = $channelDir.'/keepme.mp4';
        file_put_contents($filePath, 'video bytes');

        Storage::disk('public')->put('channels/'.$channel->id.'/poster.jpg', 'poster bytes');

        $response = $this->actingAs($user)->delete('/channels/'.$channel->id);

        $response->assertRedirect('/channels');
        $this->assertNull(Channel::find($channel->id));
        $this->assertFileExists($filePath);
        Storage::disk('public')->assertExists('channels/'.$channel->id.'/poster.jpg');
    }

    public function test_deleting_channel_with_delete_files_flag_removes_folder_from_disk()
    {
        Storage::fake('public');

        $user = User::factory()->create();
        $channel = Channel::create([
            'youtube_id' => 'UC_delete_on_chan',
            'name' => 'Delete Files On Channel',
            'url' => 'https://example.com/deleteon',
        ]);

        $downloadsDir = Setting::getStoragePath();
        $channelDir = $downloadsDir.'/Delete Files On Channel';
        mkdir($channelDir, 0755, true);
        file_put_contents($channelDir.'/removeme.mp4', 'video bytes');

        Storage::disk('public')->put('channels/'.$channel->id.'/poster.jpg', 'poster bytes');

        $response = $this->actingAs($user)->delete('/channels/'.$channel->id, ['delete_files' => '1']);

        $response->assertRedirect('/channels');
        $this->assertNull(Channel::find($channel->id));
        $this->assertDirectoryDoesNotExist($channelDir);
        Storage::disk('public')->assertMissing('channels/'.$channel->id.'/poster.jpg');
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
        $response = $this->actingAs($user)->delete('/channels/'.$channel->id, ['delete_files' => '1']);

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
        if (! file_exists($downloadsDir)) {
            mkdir($downloadsDir, 0755, true);
        }
        $parentDir = dirname($downloadsDir);
        $canaryFile = $parentDir.'/canary.txt';
        file_put_contents($canaryFile, 'must survive channel deletion');

        try {
            $response = $this->actingAs($user)->delete('/channels/'.$channel->id, ['delete_files' => '1']);

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
