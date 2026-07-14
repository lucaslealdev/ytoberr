<?php

namespace Tests\Feature;

use App\Jobs\CheckChannelForNewVideosJob;
use App\Models\Channel;
use App\Models\Setting;
use App\Models\User;
use App\Models\Video;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ProcessesTest extends TestCase
{
    use RefreshDatabase;

    public function test_processes_page_redirects_to_settings_when_advanced_mode_is_disabled()
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get('/processes');

        $response->assertRedirect('/settings');
        $response->assertSessionHas('status');
    }

    public function test_processes_page_is_accessible_when_advanced_mode_is_enabled()
    {
        Setting::set('advanced_mode', '1');
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get('/processes');

        $response->assertStatus(200);
    }

    public function test_sidebar_only_shows_processes_link_when_advanced_mode_is_enabled()
    {
        $user = User::factory()->create();

        $withoutAdvanced = $this->actingAs($user)->get('/');
        $withoutAdvanced->assertDontSee('Processes');

        Setting::set('advanced_mode', '1');

        $withAdvanced = $this->actingAs($user)->get('/');
        $withAdvanced->assertSee('Processes');
    }

    public function test_can_toggle_advanced_mode_from_settings()
    {
        $user = User::factory()->create();

        $this->actingAs($user)->post('/settings/advanced-mode', ['advanced_mode' => '1']);
        $this->assertTrue(Setting::advancedModeEnabled());

        $this->actingAs($user)->post('/settings/advanced-mode', []);
        $this->assertFalse(Setting::advancedModeEnabled());
    }

    public function test_processes_page_shows_the_currently_downloading_video_and_queues()
    {
        Setting::set('advanced_mode', '1');
        $user = User::factory()->create();

        $channel = Channel::create([
            'youtube_id' => 'UC_processes_chan',
            'name' => 'Processes Channel',
            'url' => 'https://example.com/processes',
        ]);

        $downloading = Video::create([
            'channel_id' => $channel->id,
            'youtube_id' => 'processes_downloading_vid',
            'title' => 'Downloading Video',
            'published_at' => now(),
            'status' => 'downloading',
        ]);

        $pending = Video::create([
            'channel_id' => $channel->id,
            'youtube_id' => 'processes_pending_vid',
            'title' => 'Pending Video',
            'published_at' => now(),
            'status' => 'pending',
        ]);

        $failed = Video::create([
            'channel_id' => $channel->id,
            'youtube_id' => 'processes_failed_vid',
            'title' => 'Failed Video',
            'published_at' => now(),
            'status' => 'failed',
            'last_error' => 'Something went wrong.',
        ]);

        $response = $this->actingAs($user)->get('/processes');

        $response->assertStatus(200);
        $response->assertSee('Downloading Video');
        $response->assertSee('Pending Video');
        $response->assertSee('Failed Video');
        $response->assertSee('Something went wrong.');
    }

    public function test_processes_page_lists_a_queued_channel_check_job_with_its_channel_name()
    {
        Setting::set('advanced_mode', '1');
        config(['queue.default' => 'database']);
        $user = User::factory()->create();

        $channel = Channel::create([
            'youtube_id' => 'UC_processes_job_chan',
            'name' => 'Processes Job Channel',
            'url' => 'https://example.com/processesjob',
        ]);

        CheckChannelForNewVideosJob::dispatch($channel);

        $response = $this->actingAs($user)->get('/processes');

        $response->assertStatus(200);
        $response->assertSee('Check channel for new videos');
        $response->assertSee('Processes Job Channel');
    }

    public function test_processes_page_shows_a_reserved_job_as_the_live_channel_check_activity()
    {
        Setting::set('advanced_mode', '1');
        config(['queue.default' => 'database']);
        $user = User::factory()->create();

        $channel = Channel::create([
            'youtube_id' => 'UC_processes_reserved_chan',
            'name' => 'Reserved Channel',
            'url' => 'https://example.com/processesreserved',
        ]);

        CheckChannelForNewVideosJob::dispatch($channel);
        DB::table('jobs')->update(['reserved_at' => now()->timestamp]);

        $response = $this->actingAs($user)->get('/processes');

        $response->assertStatus(200);
        $response->assertSee('Checking for new videos');
        $response->assertSee('Reserved Channel');
        $response->assertSee('Running');
    }

    public function test_can_remove_a_pending_video_from_the_processes_page()
    {
        Setting::set('advanced_mode', '1');
        $user = User::factory()->create();

        $channel = Channel::create(['youtube_id' => 'UC_remove_pending', 'name' => 'X', 'url' => 'https://example.com/x1']);
        $video = Video::create([
            'channel_id' => $channel->id,
            'youtube_id' => 'remove_pending_vid',
            'title' => 'Remove Pending Video',
            'published_at' => now(),
            'status' => 'pending',
        ]);

        $response = $this->actingAs($user)->delete(route('processes.videos.destroy', $video));

        $response->assertRedirect();
        $this->assertDatabaseMissing('videos', ['id' => $video->id]);
    }

    public function test_can_remove_a_failed_video_from_the_processes_page()
    {
        Setting::set('advanced_mode', '1');
        $user = User::factory()->create();

        $channel = Channel::create(['youtube_id' => 'UC_remove_failed', 'name' => 'X', 'url' => 'https://example.com/x2']);
        $video = Video::create([
            'channel_id' => $channel->id,
            'youtube_id' => 'remove_failed_vid',
            'title' => 'Remove Failed Video',
            'published_at' => now(),
            'status' => 'failed',
        ]);

        $response = $this->actingAs($user)->delete(route('processes.videos.destroy', $video));

        $response->assertRedirect();
        $this->assertDatabaseMissing('videos', ['id' => $video->id]);
    }

    public function test_cannot_remove_a_completed_video_from_the_processes_page()
    {
        Setting::set('advanced_mode', '1');
        $user = User::factory()->create();

        $channel = Channel::create(['youtube_id' => 'UC_remove_completed', 'name' => 'X', 'url' => 'https://example.com/x3']);
        $video = Video::create([
            'channel_id' => $channel->id,
            'youtube_id' => 'remove_completed_vid',
            'title' => 'Completed Video',
            'published_at' => now(),
            'status' => 'completed',
        ]);

        $response = $this->actingAs($user)->delete(route('processes.videos.destroy', $video));

        $response->assertStatus(422);
        $this->assertDatabaseHas('videos', ['id' => $video->id]);
    }

    public function test_can_cancel_a_queued_job_from_the_processes_page()
    {
        Setting::set('advanced_mode', '1');
        config(['queue.default' => 'database']);
        $user = User::factory()->create();

        $channel = Channel::create(['youtube_id' => 'UC_cancel_job', 'name' => 'X', 'url' => 'https://example.com/x4']);
        CheckChannelForNewVideosJob::dispatch($channel);

        $jobId = DB::table('jobs')->first()->id;

        $response = $this->actingAs($user)->delete(route('processes.jobs.destroy', $jobId));

        $response->assertRedirect();
        $this->assertDatabaseMissing('jobs', ['id' => $jobId]);
    }

    public function test_can_retry_a_failed_job_from_the_processes_page()
    {
        Setting::set('advanced_mode', '1');
        config(['queue.default' => 'database']);
        $user = User::factory()->create();

        $channel = Channel::create(['youtube_id' => 'UC_retry_job', 'name' => 'X', 'url' => 'https://example.com/x5']);
        CheckChannelForNewVideosJob::dispatch($channel);
        $queuedJob = DB::table('jobs')->first();

        DB::table('failed_jobs')->insert([
            'uuid' => json_decode($queuedJob->payload, true)['uuid'],
            'connection' => 'database',
            'queue' => 'default',
            'payload' => $queuedJob->payload,
            'exception' => 'Illuminate\Queue\TimeoutExceededException: timed out.',
            'failed_at' => now(),
        ]);
        DB::table('jobs')->delete();

        $uuid = json_decode($queuedJob->payload, true)['uuid'];

        $response = $this->actingAs($user)->post(route('processes.failed-jobs.retry', $uuid));

        $response->assertRedirect();
        $this->assertDatabaseMissing('failed_jobs', ['uuid' => $uuid]);
        $this->assertDatabaseHas('jobs', ['queue' => 'default']);
    }

    public function test_can_forget_a_failed_job_from_the_processes_page()
    {
        Setting::set('advanced_mode', '1');
        config(['queue.default' => 'database']);
        $user = User::factory()->create();

        $channel = Channel::create(['youtube_id' => 'UC_forget_job', 'name' => 'X', 'url' => 'https://example.com/x6']);
        CheckChannelForNewVideosJob::dispatch($channel);
        $queuedJob = DB::table('jobs')->first();
        $uuid = json_decode($queuedJob->payload, true)['uuid'];

        DB::table('failed_jobs')->insert([
            'uuid' => $uuid,
            'connection' => 'database',
            'queue' => 'default',
            'payload' => $queuedJob->payload,
            'exception' => 'Illuminate\Queue\TimeoutExceededException: timed out.',
            'failed_at' => now(),
        ]);
        DB::table('jobs')->delete();

        $response = $this->actingAs($user)->delete(route('processes.failed-jobs.destroy', $uuid));

        $response->assertRedirect();
        $this->assertDatabaseMissing('failed_jobs', ['uuid' => $uuid]);
        $this->assertDatabaseMissing('jobs', ['queue' => 'default']);
    }
}
