<?php

namespace Tests\Feature;

use App\Models\Channel;
use App\Models\Setting;
use App\Models\Video;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class BackfillPlexAssetsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
    }

    protected function tearDown(): void
    {
        $downloadsDir = Setting::getStoragePath();
        foreach (['Backfill Channel', 'Channel Without File'] as $dir) {
            if (file_exists($downloadsDir.'/'.$dir)) {
                exec('rm -rf '.escapeshellarg($downloadsDir.'/'.$dir));
            }
        }

        parent::tearDown();
    }

    public function test_backfill_writes_nfo_and_channel_art_for_existing_downloads()
    {
        $channel = Channel::create([
            'youtube_id' => 'UC_backfill_chan',
            'name' => 'Backfill Channel',
            'url' => 'https://example.com/backfill',
            'description' => 'A channel that predates Plex asset generation.',
        ]);

        Storage::disk('public')->put('channels/'.$channel->id.'/poster.jpg', 'fake-poster-bytes');

        $downloadsDir = Setting::getStoragePath();
        $videoDir = $downloadsDir.'/Backfill Channel/Season 2026';
        mkdir($videoDir, 0755, true);
        $oldFilename = 'Backfill Channel - s2026e0710 - Old Video [old_vid_1]';
        $relativeVideoPath = "Backfill Channel/Season 2026/{$oldFilename}.mp4";
        $relativeThumbPath = "Backfill Channel/Season 2026/{$oldFilename}-thumb.jpg";
        file_put_contents($downloadsDir.'/'.$relativeVideoPath, 'fake video bytes');
        file_put_contents($downloadsDir.'/'.$relativeThumbPath, 'fake thumb bytes');
        file_put_contents($videoDir.'/'.$oldFilename.'.nfo', 'old nfo contents');

        $video = Video::create([
            'channel_id' => $channel->id,
            'youtube_id' => 'old_vid_1',
            'title' => 'Old Video',
            'description' => 'This video was downloaded before .nfo generation existed.',
            'published_at' => '2026-07-10 12:00:00',
            'status' => 'completed',
            'file_path' => $relativeVideoPath,
            'thumbnail_path' => $relativeThumbPath,
        ]);

        Artisan::call('plex:backfill-assets');
        $output = Artisan::output();

        $this->assertStringContainsString('1 channel(s) synced', $output);
        $this->assertStringContainsString('1 video .nfo file(s) written', $output);
        $this->assertStringContainsString('1 video file(s) renamed', $output);
        $this->assertStringContainsString('1 thumbnail(s) renamed', $output);

        $channelDir = $downloadsDir.'/Backfill Channel';
        $this->assertFileExists($channelDir.'/tvshow.nfo');
        $tvshowXml = simplexml_load_file($channelDir.'/tvshow.nfo');
        $this->assertEquals('Backfill Channel', (string) $tvshowXml->title);
        $this->assertEquals('A channel that predates Plex asset generation.', (string) $tvshowXml->plot);

        $this->assertFileExists($channelDir.'/poster.jpg');
        $this->assertEquals('fake-poster-bytes', file_get_contents($channelDir.'/poster.jpg'));

        // Old video/thumbnail/.nfo files (pre-upload_date_index naming, and the old "-thumb.jpg"
        // suffix) must be gone, replaced by ones matching the current convention on disk and in
        // the DB columns. "0710" (upload month+day) + "99" (upload_date_index, defaults to 99 as
        // the only video from this channel on this date).
        $newFilename = 'Backfill Channel - s2026e071099 - Old Video [old_vid_1]';
        $this->assertFileDoesNotExist($downloadsDir.'/'.$relativeVideoPath);
        $this->assertFileDoesNotExist($downloadsDir.'/'.$relativeThumbPath);
        $this->assertFileDoesNotExist($videoDir.'/'.$oldFilename.'.nfo');

        $newVideoPath = $videoDir.'/'.$newFilename.'.mp4';
        $newThumbPath = $videoDir.'/'.$newFilename.'.jpg';
        $this->assertFileExists($newVideoPath);
        $this->assertEquals('fake video bytes', file_get_contents($newVideoPath));
        $this->assertFileExists($newThumbPath);
        $this->assertEquals('fake thumb bytes', file_get_contents($newThumbPath));

        $video->refresh();
        $this->assertEquals('Backfill Channel/Season 2026/'.$newFilename.'.mp4', $video->file_path);
        $this->assertEquals('Backfill Channel/Season 2026/'.$newFilename.'.jpg', $video->thumbnail_path);

        $videoNfoPath = $videoDir.'/'.$newFilename.'.nfo';
        $this->assertFileExists($videoNfoPath);
        $videoXml = simplexml_load_file($videoNfoPath);
        $this->assertEquals('Old Video', (string) $videoXml->title);
        $this->assertEquals('This video was downloaded before .nfo generation existed.', (string) $videoXml->plot);
        $this->assertEquals('2026', (string) $videoXml->season);
        $this->assertEquals('071099', (string) $videoXml->episode);
        $this->assertEquals('old_vid_1', (string) $videoXml->uniqueid);
    }

    public function test_backfill_skips_videos_with_missing_files_and_pending_videos()
    {
        $channel = Channel::create([
            'youtube_id' => 'UC_missing_chan',
            'name' => 'Channel Without File',
            'url' => 'https://example.com/missing',
        ]);

        // Completed but the file no longer exists on disk.
        Video::create([
            'channel_id' => $channel->id,
            'youtube_id' => 'missing_vid',
            'title' => 'Missing Video',
            'published_at' => '2026-07-10 12:00:00',
            'status' => 'completed',
            'file_path' => 'Channel Without File/Season 2026/missing.mp4',
        ]);

        // Still pending, should be ignored entirely.
        Video::create([
            'channel_id' => $channel->id,
            'youtube_id' => 'pending_vid',
            'title' => 'Pending Video',
            'published_at' => '2026-07-10 12:00:00',
            'status' => 'pending',
        ]);

        Artisan::call('plex:backfill-assets');
        $output = Artisan::output();

        $this->assertStringContainsString('Skipping Missing Video: file not found', $output);
        $this->assertStringContainsString(
            '0 channel(s) synced, 0 video .nfo file(s) written, 0 video file(s) renamed, 0 thumbnail(s) renamed, 1 skipped',
            $output
        );
    }
}
