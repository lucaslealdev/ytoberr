<?php

namespace App\Http\Controllers;

use App\Jobs\CheckChannelForNewVideosJob;
use App\Jobs\UpdateToolsJob;
use App\Models\Video;
use Carbon\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

class ProcessesController extends Controller
{
    public function index()
    {
        $downloadingVideo = Video::with('channel')->where('status', 'downloading')->first();

        $pendingVideos = Video::with('channel')
            ->where('status', 'pending')
            ->orderBy('created_at')
            ->get();

        $failedVideos = Video::with('channel')
            ->where('status', 'failed')
            ->orderBy('updated_at', 'desc')
            ->get();

        $jobs = DB::table('jobs')
            ->orderBy('created_at')
            ->get()
            ->map(fn ($job) => $this->describeJob($job));

        $failedJobs = DB::table('failed_jobs')
            ->orderBy('failed_at', 'desc')
            ->get()
            ->map(fn ($job) => $this->describeFailedJob($job));

        $checkingChannel = $jobs->first(fn (array $job) => $job['isChannelCheck'] && $job['reserved']);

        return view('processes.index', compact(
            'downloadingVideo', 'pendingVideos', 'failedVideos', 'jobs', 'failedJobs', 'checkingChannel'
        ));
    }

    public function destroyVideo(Video $video)
    {
        abort_unless(in_array($video->status, ['pending', 'failed']), 422, 'Only pending or failed videos can be removed here.');

        $video->delete();

        return back()->with('status', 'Video removed from the queue.');
    }

    public function destroyJob(int $id)
    {
        DB::table('jobs')->where('id', $id)->delete();

        return back()->with('status', 'Job removed from the queue.');
    }

    public function retryFailedJob(string $uuid)
    {
        Artisan::call('queue:retry', ['id' => [$uuid]]);

        return back()->with('status', 'Job re-queued for another attempt.');
    }

    public function destroyFailedJob(string $uuid)
    {
        Artisan::call('queue:forget', ['id' => $uuid]);

        return back()->with('status', 'Failed job forgotten.');
    }

    /**
     * @return array{id: int, label: string, channelName: ?string, isChannelCheck: bool, reserved: bool, attempts: int, queuedAt: Carbon}
     */
    private function describeJob(object $job): array
    {
        $payload = json_decode($job->payload, true);
        $displayName = $payload['displayName'] ?? 'Unknown job';
        $isChannelCheck = $displayName === CheckChannelForNewVideosJob::class;

        return [
            'id' => $job->id,
            'label' => $this->jobLabel($displayName),
            'channelName' => $isChannelCheck ? $this->channelNameFromPayload($payload) : null,
            'isChannelCheck' => $isChannelCheck,
            'reserved' => ! empty($job->reserved_at),
            'attempts' => $job->attempts,
            'queuedAt' => Carbon::createFromTimestamp($job->created_at),
        ];
    }

    /**
     * @return array{uuid: string, label: string, channelName: ?string, exceptionSummary: string, exceptionDetails: string, failedAt: Carbon}
     */
    private function describeFailedJob(object $job): array
    {
        $payload = json_decode($job->payload, true);
        $displayName = $payload['displayName'] ?? 'Unknown job';
        $isChannelCheck = $displayName === CheckChannelForNewVideosJob::class;

        $exceptionFirstLine = strtok($job->exception, "\n") ?: $job->exception;

        return [
            'uuid' => $job->uuid,
            'label' => $this->jobLabel($displayName),
            'channelName' => $isChannelCheck ? $this->channelNameFromPayload($payload) : null,
            'exceptionSummary' => $exceptionFirstLine,
            'exceptionDetails' => $job->exception,
            'failedAt' => Carbon::parse($job->failed_at),
        ];
    }

    private function jobLabel(string $displayName): string
    {
        return match ($displayName) {
            CheckChannelForNewVideosJob::class => 'Check channel for new videos',
            UpdateToolsJob::class => 'Update yt-dlp/ffmpeg tools',
            default => class_basename($displayName),
        };
    }

    /**
     * Resolve the channel name a CheckChannelForNewVideosJob's payload refers to, without
     * blowing up if the channel has since been deleted.
     */
    private function channelNameFromPayload(array $payload): ?string
    {
        $serializedCommand = $payload['data']['command'] ?? null;

        if (! $serializedCommand) {
            return null;
        }

        try {
            $job = unserialize($serializedCommand);

            return $job->channel->name ?? null;
        } catch (\Throwable) {
            return null;
        }
    }
}
