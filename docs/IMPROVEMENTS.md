# Performance & Usability Improvement Notes

This is a point-in-time audit of the codebase looking for concrete, evidence-backed
performance and usability improvements — not a wishlist. Every item below is tied to a
specific file/line and a reasoned failure/slowdown scenario. A couple of things I
suspected going in (e.g. `max_execution_time` interrupting long yt-dlp subprocess calls)
were empirically tested and ruled out, so they're not listed.

Nothing here has been implemented; this is a reference for prioritizing future work.

## Performance

### 1. Channel size totals do a filesystem stat *and* an uncached DB query per video

`Channel::totalDownloadedBytes()` (`app/Models/Channel.php:29-32`) sums `Video::fileSize()`
over every video the channel has:

```php
public function totalDownloadedBytes(): int
{
    return $this->videos->sum(fn (Video $video) => $video->fileSize() ?? 0);
}
```

`Video::fileSize()` (`app/Models/Video.php:116-125`) calls `Setting::getStoragePath()`,
which runs `Setting::get('storage_path')` — an **uncached** `SELECT ... WHERE key =
'storage_path'` query (`app/Models/Setting.php:14-19`) — then does a `file_exists()` +
`filesize()` syscall pair.

Both the channels index (`ChannelController::index`, 10 channels/page, videos eager-loaded
but not limited) and a channel's own show page call this once per channel/page render. For
a channel with a few hundred archived videos, that's a few hundred filesystem stats *and*
a few hundred repeated identical DB queries, every single time the page is rendered.

**Fix:** persist a `file_size` column on `videos`, populated once in
`DownloadNextVideo.php` right after the file is moved into place, and sum that column
directly (`$this->videos()->sum('file_size')`, one query, no filesystem I/O). Failing
that, at minimum cache `Setting::getStoragePath()` per-request.

### 2. No explicit indexes on `videos.channel_id` or `videos.status`

Unlike MySQL, **SQLite does not automatically index foreign key columns**, so
`$table->foreignId('channel_id')->constrained()` in
`database/migrations/2026_07_04_205001_create_videos_table.php` gives you the constraint
but not an index. `status` (added in
`database/migrations/2026_07_10_164353_add_queue_columns_to_videos_table.php`) has no
index either.

These are the two most-filtered columns in the app: `DownloadNextVideo.php:28`
(`where('status', 'pending')`), `ProcessesController.php:16-26` (three separate
`where('status', ...)` queries), `DashboardController.php:16`, `ChannelController::show`
(`$channel->videos()->where('status', 'completed')`), `VideoController.php:15`. All of
these currently do a full table scan as the archive grows.

**Fix:** a migration adding `$table->index('channel_id')` and `$table->index('status')`
(or a compound `['status', 'created_at']` index matching the pending-queue ordering) to
`videos`.

### 3. Download queue throughput is capped by a fixed 2-minute schedule, not actual pacing

`videos:download` is scheduled `everyTwoMinutes()->withoutOverlapping()`
(`routes/console.php:12`) and `DownloadNextVideo::handle()` pulls and processes exactly
**one** video per invocation. Even a 10-second download is followed by ~110 seconds of
pure idling before the next attempt, regardless of how conservative
`ytdlp_delay_seconds` actually needs to be. For a channel with a large backlog (e.g. a
freshly-added channel with dozens of matching videos), this caps effective throughput at
roughly one video every two minutes no matter what.

**Fix:** have the command loop and drain the queue for a bounded time budget per
invocation (still respecting `ytdlp_delay_seconds` between each video, the same pattern
already used in `CheckChannelsForNewVideos.php`), instead of exiting after a single video.

### 4. Repeated `Storage::disk('public')->exists()` checks per channel card

`channels/index.blade.php` and `channels/show.blade.php` each call
`Storage::disk('public')->exists()` twice per channel (banner + fanart) on every render.
Same class of problem as #1, much lower impact since it's a local disk stat rather than a
DB round-trip, but worth being aware of if channel counts grow large.

## Reliability (found while auditing performance — related to recent work)

### 5. ~~`CheckChannelForNewVideosJob`'s timeout no longer matches the check command it wraps~~ — Fixed

`CheckChannelForNewVideosJob::$timeout` was `600` (`app/Jobs/CheckChannelForNewVideosJob.php:21`),
sized back when `app:check-channels` made exactly two yt-dlp calls per channel. Since the
flat-playlist optimization, the command makes 1 (live-status precheck) + 1 (flat-playlist
listing) + up to 10 (one full extraction *per new video*,
`CheckChannelsForNewVideos.php:96-167`) yt-dlp calls, each individually capped at up to
240s, with the configurable `ytdlp_delay_seconds` (up to 120s) slept between *every* call.

Worst case — a channel with 10 brand-new videos and the delay set near its max — is
`90 + 120 + 240 + 10×(120+240) = 4050s`, about **6.75×** the old 600s job timeout. This
would most plausibly hit a channel on its very first check (a wide `cutoff_date`
capturing many "new" videos at once), silently killing the job mid-way through discovery.

**Fixed:** `$timeout` raised to `4500` (comfortable margin over the 4050s worst case),
docblock updated with the current math, and the regression-guard test
(`tests/Feature/CheckNewVideosTest.php`) tightened to assert `>= 4050` instead of the old,
much weaker `>= 300` — which wouldn't have caught this in the first place.

### 6. Manual "Check for New Videos" can race the 3-hourly scheduled check for the same channel

Nothing prevents `CheckChannelForNewVideosJob` (triggered manually from the UI) from
running concurrently with the scheduled `app:check-channels` sweep for the *same*
channel. Both independently query `Video::where('youtube_id', ...)->exists()` before
inserting (`CheckChannelsForNewVideos.php:87-90` and `:158-166`); if both checks pass
before either insert commits, the second `Video::create()` throws on the unique
constraint on `youtube_id`. Narrow window, but a real race.

**Fix:** add `ShouldBeUnique` (keyed by channel ID) to `CheckChannelForNewVideosJob`, or
wrap the existence-check-then-insert in a DB transaction/upsert.

## Usability

### 7. No duplicate-channel detection when adding a channel

`ChannelController::store()` (`app/Http/Controllers/ChannelController.php:44-97`) never
checks whether a channel with the resolved `youtube_id` already exists before creating a
new row — only the URL format itself is validated. Adding the same channel's URL twice
(e.g. `/channel/UC...` one time and `/@handle` another time, resolving to the same
underlying channel) silently creates a second, fully independent `Channel` row that
re-checks and re-downloads everything from scratch.

**Fix:** after resolving `channel_id` from yt-dlp metadata, check
`Channel::where('youtube_id', $channelId)->exists()` and return a friendly validation
error (same pattern already used for the invalid-URL case) instead of creating a
duplicate.

### 8. `artisan serve` + large file streaming can exhaust the whole worker pool

Production runs `php artisan serve` under supervisor
(`docker/supervisord.conf`, `[program:web]`) with `PHP_CLI_SERVER_WORKERS=4`
(`Dockerfile:96`). `MediaController::show()` streams downloaded video files directly
through that same PHP process via `response()->file()`. A single user watching or
downloading one multi-GB video — especially with a browser opening several concurrent
Range requests while scrubbing — can occupy most or all 4 workers for the full duration
of playback, leaving the rest of the dashboard unresponsive to everyone in the
meantime. (`max_execution_time` doesn't cut this short — empirically verified in this
audit that it doesn't apply to blocking stream/subprocess time — so a slow client can
hold a worker for as long as the video plays.)

**Fix:** raise `PHP_CLI_SERVER_WORKERS` further as a quick mitigation, and/or document
that self-hosters expecting concurrent multi-user access should front the container with
a real reverse proxy capable of serving large files without tying up a PHP worker for the
whole transfer.

### 9. Processes page has no pagination or cap

`ProcessesController::index()` (`app/Http/Controllers/ProcessesController.php:16-36`)
loads *all* pending videos, *all* failed videos, and the entire `jobs`/`failed_jobs`
tables unbounded. Fine at small scale, but a channel that fails repeatedly (rate
limiting, a bad cookie, etc.) can pile up a long failed-videos/failed-jobs list that
makes this page slow to render and awkward to scroll through.

**Fix:** paginate or cap each list with a "show more" affordance, consistent with the
pagination already used elsewhere (channels, videos).

### 10. No bulk actions for pending/failed videos

Retrying or removing failed videos is one-at-a-time only (`VideoController::retry`,
`ProcessesController::destroyVideo`). After an outage (IP rate-limit, expired cookies)
that fails a batch of videos at once, there's no "retry all" / "delete all failed"
action — matches the existing failure-reason tracking well enough that a bulk action
would be a natural, low-effort addition.

### 11. No search/filter on the Channels page

`videos/index.blade.php` has a full-text search box (via the `videos_fts` FTS5 table,
`Video::scopeSearch()`). `channels/index.blade.php` only has a sort dropdown, no
filter-by-name — inconsistent with the pattern already established for videos, and
increasingly useful as the channel count grows.
