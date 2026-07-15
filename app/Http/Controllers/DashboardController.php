<?php

namespace App\Http\Controllers;

use App\Models\Channel;
use App\Models\Video;

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

        return view('dashboard', compact('channelsCount', 'videosCount', 'recentVideos'));
    }
}
