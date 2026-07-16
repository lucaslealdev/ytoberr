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

    public function test_video_show_page_clamps_description_with_a_hidden_show_more_toggle()
    {
        $user = User::factory()->create();
        $channel = Channel::create([
            'youtube_id' => 'UC_description_chan',
            'name' => 'Description Channel',
            'url' => 'https://example.com/description',
        ]);

        $video = Video::create([
            'channel_id' => $channel->id,
            'youtube_id' => 'description_vid',
            'title' => 'Description Video',
            'description' => "Line one.\nLine two.\nLine three.\nLine four.\nLine five.",
            'published_at' => now(),
            'status' => 'completed',
        ]);

        $response = $this->actingAs($user)->get('/videos/'.$video->id);

        $response->assertStatus(200);
        $response->assertSee('id="video-description" class="text-gray-300 text-sm whitespace-pre-line leading-relaxed line-clamp-4"', false);
        // The toggle always renders (whether the clamp actually truncates depends on the
        // rendered width/font, which only JS can determine via scrollHeight vs clientHeight),
        // but it starts hidden so it only appears once that check reveals real overflow.
        $response->assertSee('id="toggle-video-description" class="hidden', false);
        $response->assertSee('Show more');
    }

    public function test_video_show_page_turns_a_url_in_the_description_into_a_new_tab_link()
    {
        $user = User::factory()->create();
        $channel = Channel::create([
            'youtube_id' => 'UC_description_link_chan',
            'name' => 'Description Link Channel',
            'url' => 'https://example.com/descriptionlink',
        ]);

        $video = Video::create([
            'channel_id' => $channel->id,
            'youtube_id' => 'description_link_vid',
            'title' => 'Description Link Video',
            'description' => 'Check out my site: https://example.com/mysite for more.',
            'published_at' => now(),
            'status' => 'completed',
        ]);

        $response = $this->actingAs($user)->get('/videos/'.$video->id);

        $response->assertStatus(200);
        $response->assertSee(
            '<a href="https://example.com/mysite" target="_blank" rel="noopener noreferrer" class="text-blue-400 hover:text-blue-300 underline break-all">https://example.com/mysite</a>',
            false
        );
    }

    public function test_video_show_page_keeps_a_urls_query_string_ampersands_intact_in_the_link()
    {
        $user = User::factory()->create();
        $channel = Channel::create([
            'youtube_id' => 'UC_description_query_chan',
            'name' => 'Description Query Channel',
            'url' => 'https://example.com/descriptionquery',
        ]);

        $video = Video::create([
            'channel_id' => $channel->id,
            'youtube_id' => 'description_query_vid',
            'title' => 'Description Query Video',
            'description' => 'Playlist: https://example.com/watch?v=abc&list=xyz&index=2',
            'published_at' => now(),
            'status' => 'completed',
        ]);

        $response = $this->actingAs($user)->get('/videos/'.$video->id);

        $response->assertStatus(200);
        // The "&" query separators must survive as part of one single link (as "&amp;",
        // the correctly-escaped form for an HTML attribute), rather than the regex treating
        // each "&" as ending the URL and leaving the rest as unlinked trailing text.
        $response->assertSee(
            '<a href="https://example.com/watch?v=abc&amp;list=xyz&amp;index=2" target="_blank" rel="noopener noreferrer" class="text-blue-400 hover:text-blue-300 underline break-all">https://example.com/watch?v=abc&amp;list=xyz&amp;index=2</a>',
            false
        );
    }

    public function test_video_show_page_excludes_trailing_punctuation_from_a_linked_url()
    {
        $user = User::factory()->create();
        $channel = Channel::create([
            'youtube_id' => 'UC_description_punct_chan',
            'name' => 'Description Punctuation Channel',
            'url' => 'https://example.com/descriptionpunct',
        ]);

        $video = Video::create([
            'channel_id' => $channel->id,
            'youtube_id' => 'description_punct_vid',
            'title' => 'Description Punctuation Video',
            'description' => 'See https://example.com/page. Thanks!',
            'published_at' => now(),
            'status' => 'completed',
        ]);

        $response = $this->actingAs($user)->get('/videos/'.$video->id);

        $response->assertStatus(200);
        $response->assertSee('<a href="https://example.com/page" target="_blank" rel="noopener noreferrer" class="text-blue-400 hover:text-blue-300 underline break-all">https://example.com/page</a>. Thanks!', false);
    }

    public function test_video_show_page_escapes_html_in_the_description_instead_of_rendering_it()
    {
        $user = User::factory()->create();
        $channel = Channel::create([
            'youtube_id' => 'UC_description_xss_chan',
            'name' => 'Description XSS Channel',
            'url' => 'https://example.com/descriptionxss',
        ]);

        $video = Video::create([
            'channel_id' => $channel->id,
            'youtube_id' => 'description_xss_vid',
            'title' => 'Description XSS Video',
            'description' => '<script>alert(1)</script> visit https://example.com now',
            'published_at' => now(),
            'status' => 'completed',
        ]);

        $response = $this->actingAs($user)->get('/videos/'.$video->id);

        $response->assertStatus(200);
        $response->assertDontSee('<script>alert(1)</script>', false);
        $response->assertSee('&lt;script&gt;alert(1)&lt;/script&gt;', false);
        $response->assertSee('<a href="https://example.com" target="_blank" rel="noopener noreferrer" class="text-blue-400 hover:text-blue-300 underline break-all">https://example.com</a>', false);
    }

    public function test_video_show_page_omits_description_toggle_when_video_has_no_description()
    {
        $user = User::factory()->create();
        $channel = Channel::create([
            'youtube_id' => 'UC_no_description_chan',
            'name' => 'No Description Channel',
            'url' => 'https://example.com/nodescription',
        ]);

        $video = Video::create([
            'channel_id' => $channel->id,
            'youtube_id' => 'no_description_vid',
            'title' => 'No Description Video',
            'published_at' => now(),
            'status' => 'completed',
        ]);

        $response = $this->actingAs($user)->get('/videos/'.$video->id);

        $response->assertStatus(200);
        $response->assertDontSee('toggle-video-description', false);
        $response->assertDontSee('video-description', false);
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

    public function test_video_show_page_converts_publish_and_download_times_to_the_configured_display_timezone()
    {
        // Storage is always UTC; only display should shift with app.display_timezone
        // (sourced from the TZ env var — see config/app.php).
        config(['app.display_timezone' => 'America/Sao_Paulo']);

        $user = User::factory()->create();
        $channel = Channel::create([
            'youtube_id' => 'UC_tz_chan',
            'name' => 'Timezone Channel',
            'url' => 'https://example.com/timezone',
        ]);

        $publishedAt = Carbon::create(2026, 7, 10, 14, 30, 0, 'UTC');
        $downloadedAt = Carbon::create(2026, 7, 10, 15, 0, 0, 'UTC');

        $video = Video::create([
            'channel_id' => $channel->id,
            'youtube_id' => 'tz_vid',
            'title' => 'Timezone Video',
            'published_at' => $publishedAt,
            'status' => 'completed',
            'downloaded_at' => $downloadedAt,
        ]);

        $response = $this->actingAs($user)->get('/videos/'.$video->id);

        $response->assertStatus(200);
        // Sao Paulo is UTC-3: 14:30 -> 11:30, 15:00 -> 12:00.
        $response->assertSee('11:30 AM');
        $response->assertSee('12:00 PM');
        $response->assertDontSee('2:30 PM');
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
