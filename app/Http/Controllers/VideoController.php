<?php

namespace App\Http\Controllers;

use App\Models\Video;
use Illuminate\Http\Request;

class VideoController extends Controller
{
    public function index(Request $request)
    {
        $search = trim((string) $request->query('search', ''));
        $sort = $request->query('sort', $search !== '' ? 'relevance' : 'newest');

        $query = Video::with('channel')->where('status', 'completed');

        if ($search !== '') {
            $query->search($search);
        }

        switch ($sort) {
            case 'oldest':
                $query->orderBy('published_at', 'asc');
                break;
            case 'title':
                $query->orderBy('title', 'asc');
                break;
            case 'relevance':
                if ($search !== '') {
                    $query->orderByRaw('rank');
                } else {
                    $query->orderBy('published_at', 'desc');
                }
                break;
            case 'newest':
            default:
                $query->orderBy('published_at', 'desc');
                break;
        }

        $videos = $query->paginate(12)->withQueryString();

        return view('videos.index', compact('videos', 'search', 'sort'));
    }

    public function show(Video $video)
    {
        $video->load('channel');

        $channelVideos = Video::where('channel_id', $video->channel_id)
            ->where('id', '!=', $video->id)
            ->where('status', 'completed')
            ->orderBy('published_at', 'desc')
            ->limit(8)
            ->get();

        $suggestedVideos = Video::where('channel_id', '!=', $video->channel_id)
            ->where('status', 'completed')
            ->orderBy('published_at', 'desc')
            ->limit(8)
            ->get();

        return view('videos.show', compact('video', 'channelVideos', 'suggestedVideos'));
    }

    /**
     * Re-queue a failed video for download (e.g. from a "retry" button on its warning).
     */
    public function retry(Video $video)
    {
        $video->update([
            'status' => 'pending',
            'retries' => 0,
            'prevent_download' => false,
            'unavailable_reason' => null,
            'last_error' => null,
        ]);

        return back()->with('status', "\"{$video->title}\" has been re-queued for download.");
    }
}
