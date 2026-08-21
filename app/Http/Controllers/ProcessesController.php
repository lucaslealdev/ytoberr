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
            ->paginate(10, ['*'], 'pending_page')
            ->withQueryString();

        $failedVideos = Video::with('channel')
            ->where('status', 'failed')
            ->orderBy('updated_at', 'desc')
            ->paginate(10, ['*'], 'failed_videos_page')
            ->withQueryString();

        $jobs = DB::table('jobs')
            ->orderBy('created_at')
            ->paginate(10, ['*'], 'jobs_page')
            ->withQueryString()
            ->through(fn ($job) => $this->describeJob($job));

        $failedJobs = DB::table('failed_jobs')
            ->orderBy('failed_at', 'desc')
            ->paginate(10, ['*'], 'failed_jobs_page')
            ->withQueryString()
            ->through(fn ($job) => $this->describeFailedJob($job));

        // The reserved channel-check job (if any) drives the "Live Activity" banner. This is
        // looked up independently of the paginated $jobs list above, since the reserved job
        // may not be on whichever page of the queue the user currently has open.
        $checkingChannel = DB::table('jobs')
            ->whereNotNull('reserved_at')
            ->get()
            ->map(fn ($job) => $this->describeJob($job))
            ->first(fn (array $job) => $job['isChannelCheck']);

        return view('processes.index', compact(
            'downloadingVideo', 'pendingVideos', 'failedVideos', 'jobs', 'failedJobs', 'checkingChannel'
        ));
    }

    /**
     * Signal a stuck/unwanted in-progress download to stop, and record the cancellation on the
     * video row directly instead of just flagging it and waiting for DownloadNextVideo to notice.
     *
     * The original approach only set cancel_requested_at and relied on the still-running
     * `videos:download` process to observe it once its blocking wait() call returned (see
     * DownloadNextVideo::processVideo). That leaves the video stuck showing as "downloading"
     * forever whenever that process isn't actually there to notice anymore — e.g. it already
     * crashed, or the whole server was restarted — since nothing else ever moves the row out of
     * "downloading". Updating the status here makes Cancel take effect immediately and
     * unconditionally. The posix_kill is still attempted best-effort in case a real process is
     * alive; if DownloadNextVideo *is* still running and later reaches its own post-kill handling,
     * cancel_requested_at being set makes that a harmless no-op over the same fields.
     */
    public function cancelDownload(Video $video)
    {
        abort_unless($video->status === 'downloading', 422, 'Only a video that is currently downloading can be cancelled.');

        if ($video->download_pid) {
            // Negative PID targets the whole process group, not just the tracked PID itself —
            // see the posix_setpgid() call in YtDlpWrapper::runCommand for why that matters
            // (yt-dlp can spawn ffmpeg as a child to merge formats).
            @posix_kill(-$video->download_pid, SIGKILL);
        }

        $video->update([
            'status' => 'failed',
            'progress_percent' => null,
            'download_pid' => null,
            'cancel_requested_at' => now(),
            'last_error' => 'Cancelled by user.',
        ]);

        return back()->with('status', 'Download cancelled.');
    }

    public function destroyVideo(Video $video)
    {
        abort_unless(in_array($video->status, ['pending', 'failed']), 422, 'Only pending or failed videos can be removed here.');

        $video->delete();

        return back()->with('status', 'Video removed from the queue.');
    }

    /**
     * Re-queue every currently-failed video for download (e.g. after an outage that
     * failed a whole batch at once, such as an IP rate-limit or expired cookies).
     */
    public function retryAllFailedVideos()
    {
        $count = Video::where('status', 'failed')->count();

        Video::where('status', 'failed')->update([
            'status' => 'pending',
            'retries' => 0,
            'prevent_download' => false,
            'unavailable_reason' => null,
            'last_error' => null,
        ]);

        return back()->with('status', "{$count} failed video(s) re-queued for download.");
    }

    /**
     * Permanently remove every currently-failed video from the queue.
     */
    public function destroyAllFailedVideos()
    {
        $count = Video::where('status', 'failed')->count();

        Video::where('status', 'failed')->delete();

        return back()->with('status', "{$count} failed video(s) removed from the queue.");
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
