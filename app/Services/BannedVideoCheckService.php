<?php

namespace App\Services;

use App\Models\Setting;
use App\Models\Video;
use App\Models\Warning;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class BannedVideoCheckService
{
    /**
     * How many videos get checked per invocation of the videos:check-banned-videos command
     * (scheduled every minute — see routes/console.php), so a large library doesn't hammer
     * img.youtube.com with hundreds of requests at once.
     */
    public const BATCH_SIZE = 60;

    /**
     * Minimum time between two checks of the same video, regardless of how many runs get
     * started in the meantime — so a user re-triggering the check (or two runs overlapping)
     * doesn't re-hit img.youtube.com for videos it already looked at recently.
     */
    public const RECHECK_INTERVAL_DAYS = 7;

    public function isRunning(): bool
    {
        return Setting::get('banned_check_started_at') !== null;
    }

    /**
     * Arm a new run: from the next scheduled tick of videos:check-banned-videos onward, every
     * downloaded video not already marked banned and not checked within the last
     * self::RECHECK_INTERVAL_DAYS days gets its live thumbnail checked, in batches of
     * self::BATCH_SIZE, until none remain.
     */
    public function start(): void
    {
        Setting::set('banned_check_started_at', now()->toDateTimeString());
        Setting::set('banned_check_total', (string) $this->eligibleQuery()->count());
        Setting::set('banned_check_checked', '0');
        Setting::set('banned_check_found', '0');
    }

    /**
     * Stop an in-progress run early. Whatever's already been checked keeps its result; videos
     * not yet reached simply won't be checked until the next run.
     */
    public function cancel(): void
    {
        Setting::set('banned_check_started_at', null);
    }

    /**
     * @return array{running: bool, total: int, checked: int, found: int, started_at: ?Carbon}
     */
    public function progress(): array
    {
        $startedAt = Setting::get('banned_check_started_at');

        return [
            'running' => $startedAt !== null,
            'total' => (int) Setting::get('banned_check_total', '0'),
            'checked' => (int) Setting::get('banned_check_checked', '0'),
            'found' => (int) Setting::get('banned_check_found', '0'),
            'started_at' => $startedAt !== null ? Carbon::parse($startedAt) : null,
        ];
    }

    /**
     * Process the next batch for the in-progress run (a no-op if no run is armed). Called once
     * a minute by the scheduled videos:check-banned-videos command.
     */
    public function processNextBatch(): void
    {
        if (Setting::get('banned_check_started_at') === null) {
            return;
        }

        $batch = $this->eligibleQuery()
            ->orderByRaw('CASE WHEN banned_checked_at IS NULL THEN 0 ELSE 1 END, banned_checked_at ASC, id ASC')
            ->limit(self::BATCH_SIZE)
            ->get();

        if ($batch->isEmpty()) {
            $this->finish();

            return;
        }

        $checked = (int) Setting::get('banned_check_checked', '0');
        $found = (int) Setting::get('banned_check_found', '0');

        foreach ($batch as $video) {
            $isBanned = $this->isBanned($video);

            $video->update([
                'is_banned' => $isBanned,
                'banned_checked_at' => now(),
            ]);

            $checked++;
            if ($isBanned) {
                $found++;
            }
        }

        Setting::set('banned_check_checked', (string) $checked);
        Setting::set('banned_check_found', (string) $found);

        // Fewer rows than requested means this was the last batch — nothing eligible remains.
        if ($batch->count() < self::BATCH_SIZE) {
            $this->finish();
        }
    }

    /**
     * Whether $video's thumbnail is gone (404), meaning it's been removed/banned from YouTube —
     * https://img.youtube.com/vi/<id>/0.jpg still returns 200 for anything still public or
     * unlisted. A network error or any other status is treated conservatively as "not banned"
     * rather than risking a false positive from a transient hiccup.
     */
    private function isBanned(Video $video): bool
    {
        try {
            $response = Http::timeout(10)->connectTimeout(5)
                ->get("https://img.youtube.com/vi/{$video->youtube_id}/0.jpg");

            return $response->status() === 404;
        } catch (\Throwable $e) {
            Log::warning("Banned-video check failed for {$video->youtube_id}: ".$e->getMessage());

            return false;
        }
    }

    private function eligibleQuery(): Builder
    {
        return Video::where('status', 'completed')
            // Once a video is marked banned it's permanent — never re-checked by a later run.
            ->where('is_banned', false)
            ->where(function (Builder $query) {
                $query->whereNull('banned_checked_at')
                    ->orWhere('banned_checked_at', '<=', now()->subDays(self::RECHECK_INTERVAL_DAYS));
            });
    }

    private function finish(): void
    {
        $checked = (int) Setting::get('banned_check_checked', '0');
        $found = (int) Setting::get('banned_check_found', '0');

        Setting::set('banned_check_started_at', null);

        Log::info("Banned-video check complete: checked {$checked} video(s), found {$found} newly banned.");

        if ($found > 0) {
            Warning::log(
                'banned_videos_found',
                "Banned-video check complete: {$found} downloaded video(s) appear to have been removed from YouTube.",
                "Checked {$checked} video(s) in total."
            );
        }
    }
}
