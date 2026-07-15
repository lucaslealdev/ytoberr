# Performance & Usability Improvement Notes

This is a point-in-time audit of the codebase looking for concrete, evidence-backed
performance and usability improvements — not a wishlist. Every item below is tied to a
specific file/line and a reasoned failure/slowdown scenario. A couple of things I
suspected going in (e.g. `max_execution_time` interrupting long yt-dlp subprocess calls)
were empirically tested and ruled out, so they're not listed.

All items below have been implemented and merged.

## Performance

### 1. ~~Channel size totals do a filesystem stat *and* an uncached DB query per video~~ — Fixed

`Channel::totalDownloadedBytes()` (`app/Models/Channel.php`) summed `Video::fileSize()`
over every video the channel has, and `Video::fileSize()` (`app/Models/Video.php`) called
`Setting::getStoragePath()` — an **uncached** `SELECT ... WHERE key = 'storage_path'`
query (`app/Models/Setting.php`) — then did a `file_exists()` + `filesize()` syscall pair,
on every single call. Both the channels index (10 channels/page) and a channel's own show
page called this once per channel/page render, so a channel with a few hundred archived
videos meant a few hundred filesystem stats *and* a few hundred repeated identical DB
queries, every single time the page was rendered.

**Fixed:** a `file_size` column is now populated once on `videos` at download time
(`DownloadNextVideo.php`), with `Video::fileSize()` preferring the cached column and only
falling back to a live stat for videos downloaded before the column existed.
`Channel::totalDownloadedBytes()` sums `file_size` directly via a DB aggregate query
instead of iterating the collection. A one-time operation backfills `file_size` for
existing videos.

### 2. ~~No explicit indexes on `videos.channel_id` or `videos.status`~~ — Fixed

Unlike MySQL, SQLite does not automatically index foreign key columns, so `channel_id`
(via `constrained()`) had no index, and neither did `status` — despite both being the
most-filtered columns in the app (`DownloadNextVideo.php`, `ProcessesController.php`,
`DashboardController.php`, `ChannelController::show`, `VideoController.php`). All of these
did a full table scan as the archive grows.

**Fixed:** a migration adds an index on `channel_id` and a compound index on
`['status', 'created_at']` — the latter fully indexes `videos:download`'s "next pending
video" query (filter *and* sort), its single hottest query, while still satisfying
plain `status`-only filters elsewhere via the leftmost-prefix rule.

### 3. ~~Download queue throughput is capped by a fixed 2-minute schedule, not actual pacing~~ — Fixed

`videos:download` was scheduled `everyTwoMinutes()->withoutOverlapping()` and processed
exactly one video per invocation — even a 10-second download was followed by ~110s of
pure idling before the next attempt, capping effective throughput at roughly one video
every two minutes regardless of how conservative `ytdlp_delay_seconds` actually needs to
be.

**Fixed:** `DownloadNextVideo::handle()` now loops and drains the pending queue within a
single invocation, pacing itself between videos via the existing delay setting and
bounded by an internal wall-clock time budget (checked only *between* videos, never
interrupting an in-progress download) so a busy invocation still wraps up and hands
control back to the scheduler.

### 4. ~~Repeated `Storage::disk('public')->exists()` checks per channel card~~ — Fixed

`channels/index.blade.php` and `channels/show.blade.php` each called
`Storage::disk('public')->exists()` twice per channel (banner + fanart) on every render.

**Fixed:** `banner_path`/`fanart_path` columns are now set once by `ChannelService` when
the images are actually written to disk, and the views read those columns directly
instead of stat-ing the filesystem. A one-time operation backfills existing channels.

## Reliability (found while auditing performance — related to recent work)

### 5. ~~`CheckChannelForNewVideosJob`'s timeout no longer matches the check command it wraps~~ — Fixed

`CheckChannelForNewVideosJob::$timeout` was `600`, sized back when `app:check-channels`
made exactly two yt-dlp calls per channel. Since the flat-playlist optimization, the
command makes up to 12 calls per channel (live-status precheck + flat-playlist listing +
one full extraction per newly-discovered video), each with the configurable
`ytdlp_delay_seconds` slept between every call — a worst case of ~4050s, about 6.75× the
old timeout. This would most plausibly hit a channel's very first check, silently killing
the job mid-way through discovery.

**Fixed:** `$timeout` raised to `4500`, docblock updated with the current math, and the
regression-guard test tightened to assert `>= 4050` instead of the old, much weaker
`>= 300`.

### 6. ~~Manual "Check for New Videos" can race the 3-hourly scheduled check for the same channel~~ — Fixed

Nothing prevented `CheckChannelForNewVideosJob` (triggered manually) from running
concurrently with the scheduled `app:check-channels` sweep for the *same* channel. Both
independently checked "does this video exist?" before inserting; if both passed that
check before either insert committed, the second `Video::create()` would throw on the
unique constraint on `youtube_id`.

**Fixed:** `CheckChannelForNewVideosJob` is now `ShouldBeUnique` (keyed by channel ID),
closing the race between two *queued* instances of the job. Since that alone has no
visibility into the *scheduled* sweep (which doesn't go through the job at all), a
per-channel `Cache::lock` inside `CheckChannelsForNewVideos::handle()` closes that race
too — a channel already being checked elsewhere is skipped for that run rather than
raced. As defense in depth, a duplicate insert that still slips through is caught and
logged instead of crashing the run.

## Usability

### 7. ~~No duplicate-channel detection when adding a channel~~ — Fixed

`ChannelController::store()` never checked whether a channel with the resolved
`youtube_id` already existed before creating a new row — only the URL format itself was
validated. Adding the same channel via two different URL forms (e.g. `/channel/UC...` vs
`/@handle`) silently created a second, fully independent `Channel` row that re-checks and
re-downloads everything from scratch.

**Fixed:** after resolving `channel_id` from yt-dlp metadata, the controller now checks
for an existing channel with that `youtube_id` and returns a friendly validation error
naming the existing channel instead of creating a duplicate.

### 8. ~~`artisan serve` + large file streaming can exhaust the whole worker pool~~ — Fixed

Production ran `php artisan serve` with `PHP_CLI_SERVER_WORKERS=4`, and
`MediaController::show()` streams downloaded video files through that same pool. A single
user watching or downloading one multi-GB video — especially with a browser opening
several concurrent Range requests while scrubbing — could occupy most or all 4 workers
for the full duration of playback, leaving the rest of the dashboard unresponsive to
everyone else in the meantime.

**Fixed:** `PHP_CLI_SERVER_WORKERS` raised to `12` (Dockerfile and `.env.example`), and a
new README section documents the tradeoff and recommends a real reverse proxy for
deployments expecting heavier concurrent access.

### 9. ~~Processes page has no pagination or cap~~ — Fixed

`ProcessesController::index()` loaded *all* pending videos, *all* failed videos, and the
entire `jobs`/`failed_jobs` tables unbounded. A channel failing repeatedly (rate
limiting, a bad cookie, etc.) could pile up a long list that made the page slow to render
and awkward to scroll through.

**Fixed:** each of the four lists now paginates independently via its own `pageName`
query parameter, so they coexist on one URL without colliding. The "Live Activity"
banner's reserved-job lookup was split into its own unpaginated query, since it needs to
reflect the true queue state regardless of which page is open.

### 10. ~~No bulk actions for pending/failed videos~~ — Fixed

Retrying or removing failed videos was one-at-a-time only. After an outage (IP
rate-limit, expired cookies) that failed a batch of videos at once, there was no "retry
all" / "delete all failed" action.

**Fixed:** "Retry All Failed" and "Delete All Failed" buttons added to the Failed Videos
section of the Processes page, mirroring the existing single-video actions' field resets
and scoped to `status = 'failed'` only. Delete requires confirmation.

### 11. ~~No search/filter on the Channels page~~ — Fixed

The Videos page had a full-text search box; the Channels page only had a sort dropdown —
inconsistent with the established pattern, and increasingly useful as the channel count
grows.

**Fixed:** added a search-by-name (and `youtube_id`) box to the Channels page, using a
plain `LIKE` filter (no FTS5 infrastructure needed for what's typically a short list),
composing with the existing sort options and preserved across pagination.
