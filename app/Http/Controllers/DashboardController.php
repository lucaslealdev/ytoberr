<?php

namespace App\Http\Controllers;

use App\Models\Channel;
use App\Models\Setting;
use App\Models\Video;
use App\Models\Warning;

class DashboardController extends Controller
{
    public function index()
    {
        $channelsCount = Channel::count();
        $videosCount = Video::where('status', 'completed')->count();

        $recentVideos = Video::with('channel')
            ->where('status', 'completed')
            ->orderBy('downloaded_at', 'desc')
            ->limit(10)
            ->get();

        $pendingVideosCount = Video::whereIn('status', ['pending', 'downloading'])->count();
        $failedVideosCount = Video::where('status', 'failed')->count();
        $warningsCount = Warning::count();

        $diskUsedPercent = Setting::diskUsagePercent(Setting::getStoragePath());
        $diskBarColor = Setting::diskUsageColorClass($diskUsedPercent);

        // Mirrors DownloadNextVideo::incrementConsecutiveFailures's own suspension threshold:
        // once 3 downloads have failed back to back, the whole pending queue gets marked
        // failed and stays that way until a subsequent download succeeds (which resets this
        // setting to 0) — so this counter doubling as "queue is suspended" is exact, not a
        // separate flag that could drift out of sync with it.
        $queueSuspended = (int) Setting::get('consecutive_failures', '0') >= 3;

        $storageGrowthSeries = $this->storageGrowthSeries();

        return view('dashboard', compact(
            'channelsCount', 'videosCount', 'recentVideos',
            'pendingVideosCount', 'failedVideosCount', 'warningsCount',
            'diskUsedPercent', 'diskBarColor', 'queueSuspended', 'storageGrowthSeries'
        ));
    }

    /**
     * Daily cumulative downloaded-bytes total for the last 30 days, to plot a storage-growth
     * trend on the dashboard. Each day's value is the running total up to and including that
     * day, so the series is always non-decreasing — useful for eyeballing how fast the
     * downloads directory is filling up and roughly when it might run out of space.
     *
     * Only counts the cached `file_size` column (populated at download time), not a live
     * filesystem fallback for older videos predating that column — unlike
     * Channel::totalDownloadedBytes(), stat'ing every such video on every dashboard load would
     * be too expensive here. That means the true total can run slightly ahead of this chart on
     * an install with videos downloaded before file_size existed.
     *
     * @return array<int, array{date: string, bytes: int}>
     */
    private function storageGrowthSeries(): array
    {
        $days = 30;
        $windowStart = now()->subDays($days - 1)->startOfDay();

        $baselineBytes = (int) Video::where('status', 'completed')
            ->whereNotNull('downloaded_at')
            ->where('downloaded_at', '<', $windowStart)
            ->sum('file_size');

        $dailyBytes = Video::where('status', 'completed')
            ->whereNotNull('downloaded_at')
            ->where('downloaded_at', '>=', $windowStart)
            ->selectRaw('date(downloaded_at) as day, SUM(COALESCE(file_size, 0)) as bytes')
            ->groupBy('day')
            ->pluck('bytes', 'day');

        $series = [];
        $cumulative = $baselineBytes;

        for ($i = 0; $i < $days; $i++) {
            $date = $windowStart->copy()->addDays($i);
            $cumulative += (int) ($dailyBytes[$date->toDateString()] ?? 0);

            $series[] = [
                'date' => $date->format('M j'),
                'bytes' => $cumulative,
            ];
        }

        return $series;
    }
}
