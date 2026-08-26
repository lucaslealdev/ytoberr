<?php

namespace Tests\Feature;

use App\Models\Channel;
use App\Models\Setting;
use App\Models\User;
use App\Models\Video;
use App\Models\Warning;
use App\Services\BannedVideoCheckService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class CheckBannedVideosTest extends TestCase
{
    use RefreshDatabase;

    private function makeCompletedVideo(Channel $channel, string $youtubeId): Video
    {
        return Video::create([
            'channel_id' => $channel->id,
            'youtube_id' => $youtubeId,
            'title' => 'Video '.$youtubeId,
            'published_at' => now(),
            'status' => 'completed',
        ]);
    }

    public function test_command_is_a_no_op_when_no_check_is_running()
    {
        Http::fake();

        Artisan::call('videos:check-banned-videos');

        Http::assertNothingSent();
    }

    public function test_a_batch_marks_videos_as_banned_based_on_thumbnail_status()
    {
        $channel = Channel::create(['youtube_id' => 'UC_banned_chan', 'name' => 'Banned Channel', 'url' => 'https://example.com/banned']);

        $stillUp = $this->makeCompletedVideo($channel, 'still_up_vid');
        $removed = $this->makeCompletedVideo($channel, 'removed_vid');

        Http::fake([
            'https://img.youtube.com/vi/still_up_vid/0.jpg' => Http::response('', 200),
            'https://img.youtube.com/vi/removed_vid/0.jpg' => Http::response('', 404),
        ]);

        app(BannedVideoCheckService::class)->start();

        Artisan::call('videos:check-banned-videos');

        $this->assertFalse((bool) $stillUp->refresh()->is_banned);
        $this->assertTrue((bool) $removed->refresh()->is_banned);
        $this->assertNotNull($stillUp->banned_checked_at);
        $this->assertNotNull($removed->banned_checked_at);

        // A single batch covered both (well under BATCH_SIZE), so the run is finished.
        $this->assertFalse(app(BannedVideoCheckService::class)->isRunning());
    }

    public function test_a_video_already_marked_banned_is_never_checked_again()
    {
        // Regression test: once banned, a video must be permanently excluded from future
        // checks -- it should never even generate an HTTP request in a later run.
        $channel = Channel::create(['youtube_id' => 'UC_banned_chan', 'name' => 'Banned Channel', 'url' => 'https://example.com/banned']);

        $alreadyBanned = $this->makeCompletedVideo($channel, 'already_banned_vid');
        $alreadyBanned->update(['is_banned' => true, 'banned_checked_at' => now()->subDay()]);

        Http::fake();

        app(BannedVideoCheckService::class)->start();
        Artisan::call('videos:check-banned-videos');

        Http::assertNothingSent();
        $this->assertTrue((bool) $alreadyBanned->refresh()->is_banned);
    }

    public function test_a_run_processes_at_most_batch_size_videos_per_tick_and_finishes_over_multiple_ticks()
    {
        $channel = Channel::create(['youtube_id' => 'UC_batch_chan', 'name' => 'Batch Channel', 'url' => 'https://example.com/batch']);

        $total = BannedVideoCheckService::BATCH_SIZE + 5;
        for ($i = 0; $i < $total; $i++) {
            $this->makeCompletedVideo($channel, 'batch_vid_'.$i);
        }

        Http::fake(fn () => Http::response('', 200));

        app(BannedVideoCheckService::class)->start();

        Artisan::call('videos:check-banned-videos');
        Http::assertSentCount(BannedVideoCheckService::BATCH_SIZE);
        $this->assertTrue(app(BannedVideoCheckService::class)->isRunning());
        $this->assertSame(BannedVideoCheckService::BATCH_SIZE, Video::whereNotNull('banned_checked_at')->count());

        Artisan::call('videos:check-banned-videos');
        Http::assertSentCount(BannedVideoCheckService::BATCH_SIZE + 5);
        $this->assertFalse(app(BannedVideoCheckService::class)->isRunning());
        $this->assertSame($total, Video::whereNotNull('banned_checked_at')->count());
    }

    public function test_finishing_a_run_logs_a_warning_only_when_banned_videos_were_found()
    {
        $channel = Channel::create(['youtube_id' => 'UC_warn_chan', 'name' => 'Warn Channel', 'url' => 'https://example.com/warn']);
        $removed = $this->makeCompletedVideo($channel, 'warn_removed_vid');

        Http::fake(['https://img.youtube.com/vi/warn_removed_vid/0.jpg' => Http::response('', 404)]);

        app(BannedVideoCheckService::class)->start();
        Artisan::call('videos:check-banned-videos');

        $this->assertTrue((bool) $removed->refresh()->is_banned);
        $this->assertDatabaseHas('warnings', ['source' => 'banned_videos_found']);
    }

    public function test_finishing_a_run_with_no_banned_videos_does_not_log_a_warning()
    {
        $channel = Channel::create(['youtube_id' => 'UC_clean_chan', 'name' => 'Clean Channel', 'url' => 'https://example.com/clean']);
        $this->makeCompletedVideo($channel, 'clean_vid');

        Http::fake(fn () => Http::response('', 200));

        app(BannedVideoCheckService::class)->start();
        Artisan::call('videos:check-banned-videos');

        $this->assertSame(0, Warning::count());
    }

    public function test_a_network_error_is_treated_conservatively_as_not_banned()
    {
        $channel = Channel::create(['youtube_id' => 'UC_err_chan', 'name' => 'Error Channel', 'url' => 'https://example.com/err']);
        $video = $this->makeCompletedVideo($channel, 'flaky_vid');

        Http::fake(fn () => throw new ConnectionException('timed out'));

        app(BannedVideoCheckService::class)->start();
        Artisan::call('videos:check-banned-videos');

        $video->refresh();
        $this->assertFalse((bool) $video->is_banned);
        $this->assertNotNull($video->banned_checked_at);
    }

    public function test_cancelling_a_run_stops_further_batches()
    {
        $channel = Channel::create(['youtube_id' => 'UC_cancel_chan', 'name' => 'Cancel Channel', 'url' => 'https://example.com/cancel']);
        $video = $this->makeCompletedVideo($channel, 'cancel_vid');

        Http::fake();

        $service = app(BannedVideoCheckService::class);
        $service->start();
        $service->cancel();

        Artisan::call('videos:check-banned-videos');

        Http::assertNothingSent();
        $this->assertNull($video->refresh()->banned_checked_at);
        $this->assertFalse($service->isRunning());
    }

    public function test_a_video_checked_within_the_last_week_is_skipped_by_a_new_run()
    {
        // Regression test: each video must only be re-checked once a week, regardless of how
        // many times a check gets started in the meantime.
        $channel = Channel::create(['youtube_id' => 'UC_cooldown_chan', 'name' => 'Cooldown Channel', 'url' => 'https://example.com/cooldown']);
        $recentlyChecked = $this->makeCompletedVideo($channel, 'recently_checked_vid');
        $recentlyChecked->update(['banned_checked_at' => now()->subDays(2)]);

        Http::fake();

        $service = app(BannedVideoCheckService::class);
        $service->start();

        $this->assertSame(0, $service->progress()['total']);

        Artisan::call('videos:check-banned-videos');

        Http::assertNothingSent();
    }

    public function test_a_video_checked_more_than_a_week_ago_becomes_eligible_again()
    {
        $channel = Channel::create(['youtube_id' => 'UC_stale_chan', 'name' => 'Stale Channel', 'url' => 'https://example.com/stale']);
        $staleChecked = $this->makeCompletedVideo($channel, 'stale_checked_vid');
        $staleChecked->update(['banned_checked_at' => now()->subDays(BannedVideoCheckService::RECHECK_INTERVAL_DAYS + 1)]);

        Http::fake(['https://img.youtube.com/vi/stale_checked_vid/0.jpg' => Http::response('', 200)]);

        app(BannedVideoCheckService::class)->start();
        Artisan::call('videos:check-banned-videos');

        Http::assertSentCount(1);
        $this->assertTrue(Carbon::parse($staleChecked->refresh()->banned_checked_at)->greaterThan(now()->subMinute()));
    }

    public function test_starting_a_check_when_everything_was_recently_checked_reports_nothing_to_check()
    {
        $channel = Channel::create(['youtube_id' => 'UC_fresh_chan', 'name' => 'Fresh Channel', 'url' => 'https://example.com/fresh']);
        $recentlyChecked = $this->makeCompletedVideo($channel, 'already_fresh_vid');
        $recentlyChecked->update(['banned_checked_at' => now()->subHours(2)]);

        $user = User::factory()->create();
        $response = $this->actingAs($user)->post('/settings/banned-videos/start');

        $response->assertRedirect();
        $response->assertSessionHas('status', 'Nothing to check — every video was already checked within the last week.');
        $this->assertFalse(app(BannedVideoCheckService::class)->isRunning());
    }

    public function test_starting_a_check_via_settings_sets_the_total_from_eligible_videos()
    {
        $channel = Channel::create(['youtube_id' => 'UC_settings_chan', 'name' => 'Settings Channel', 'url' => 'https://example.com/settings']);
        $this->makeCompletedVideo($channel, 'eligible_vid');
        $pending = Video::create([
            'channel_id' => $channel->id,
            'youtube_id' => 'pending_vid',
            'title' => 'Pending video',
            'published_at' => now(),
            'status' => 'pending',
        ]);
        $alreadyBanned = $this->makeCompletedVideo($channel, 'already_banned_vid2');
        $alreadyBanned->update(['is_banned' => true]);

        $user = User::factory()->create();
        $response = $this->actingAs($user)->post('/settings/banned-videos/start');

        $response->assertRedirect();
        $progress = app(BannedVideoCheckService::class)->progress();
        $this->assertTrue($progress['running']);
        $this->assertSame(1, $progress['total']);
    }

    public function test_settings_page_shows_progress_while_a_check_is_running()
    {
        Setting::set('banned_check_started_at', now()->toDateTimeString());
        Setting::set('banned_check_total', '10');
        Setting::set('banned_check_checked', '3');
        Setting::set('banned_check_found', '1');

        $user = User::factory()->create();
        $response = $this->actingAs($user)->get('/settings');

        $response->assertStatus(200);
        $response->assertSee('Checked 3 of 10 videos', false);
        $response->assertSee('Cancel Check');
    }

    public function test_cancelling_via_settings_stops_the_running_check()
    {
        app(BannedVideoCheckService::class)->start();

        $user = User::factory()->create();
        $response = $this->actingAs($user)->post('/settings/banned-videos/cancel');

        $response->assertRedirect();
        $this->assertFalse(app(BannedVideoCheckService::class)->isRunning());
    }
}
