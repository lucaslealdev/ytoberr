<?php

namespace App\Http\Controllers;

use App\Models\Channel;
use App\Models\Video;
use App\Services\ChannelService;
use App\Services\VideoDeletionService;
use App\Services\YtDlpWrapper;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class VideoController extends Controller
{
    public function __construct(private VideoDeletionService $videoDeletionService) {}

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

    /**
     * Queue a single video for download from its URL, registering its channel first if this
     * is the first video seen from it. The chosen quality is applied to the channel (there's
     * no per-video quality — DownloadNextVideo always resolves quality from video->channel),
     * so it also becomes that channel's quality for every future download, not just this one.
     */
    public function store(Request $request)
    {
        $request->validate([
            'url' => ['required', 'url'],
            'quality' => ['required', 'in:480p,720p,1080p'],
        ]);

        $wrapper = app(YtDlpWrapper::class);
        $metadata = $wrapper->getMetadata($request->url, [], ['-J']);

        if (! $metadata || ($metadata['_type'] ?? 'video') !== 'video' || empty($metadata['id'])) {
            return back()->withErrors(['url' => 'Could not fetch video information. Make sure this is a single video URL.']);
        }

        $youtubeId = $metadata['id'];

        if (Video::where('youtube_id', $youtubeId)->exists()) {
            return back()->withErrors(['url' => 'This video has already been added.']);
        }

        $channelYoutubeId = $metadata['channel_id'] ?? null;
        $channelUrl = $metadata['channel_url'] ?? $metadata['uploader_url'] ?? null;
        $channelName = $metadata['channel'] ?? $metadata['uploader'] ?? 'Unknown';

        if (! $channelYoutubeId || ! $channelUrl) {
            return back()->withErrors(['url' => 'Could not determine the channel for this video.']);
        }

        $channel = Channel::where('youtube_id', $channelYoutubeId)->first();

        if ($channel) {
            $channel->update(['download_quality' => $request->quality]);
        } else {
            try {
                $channel = app(ChannelService::class)->createChannel($channelYoutubeId, $channelName, $channelUrl, null, $request->quality);
            } catch (\Exception $e) {
                Log::error('Database error while creating channel from video URL: '.$e->getMessage());

                return back()->withErrors(['url' => 'Database error: '.$e->getMessage()]);
            }
        }

        $publishedAt = isset($metadata['timestamp'])
            ? Carbon::createFromTimestamp($metadata['timestamp'])
            : (isset($metadata['upload_date']) ? Carbon::parse($metadata['upload_date']) : now());

        $video = Video::create([
            'channel_id' => $channel->id,
            'youtube_id' => $youtubeId,
            'title' => $metadata['title'] ?? 'Unknown Title',
            'description' => $metadata['description'] ?? null,
            'published_at' => $publishedAt->toDateTimeString(),
            'duration' => $metadata['duration'] ?? null,
            'status' => 'pending',
        ]);

        return redirect('/videos')->with('status', "\"{$video->title}\" has been added to the download queue.");
    }

    public function show(Video $video)
    {
        // A manually-deleted-but-blacklisted video (see destroy()) is only kept around so it
        // never gets re-queued; it must stay unreachable everywhere, including a direct link.
        abort_if($video->status === 'deleted', 404);

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

    /**
     * Manually delete a video from its own show page, the video listing, or a channel's video
     * grid. Two independent, opt-in choices control what actually happens:
     *
     * - delete_files: also remove the downloaded video, thumbnail and .nfo from disk.
     * - prevent_redownload: keep the row as an invisible blacklist marker instead of removing
     *   it outright, so CheckChannelsForNewVideos's "already known" check keeps skipping it
     *   forever. Without this, the row is deleted entirely and a still-recent upload could be
     *   discovered and downloaded again by a future channel check.
     */
    public function destroy(Request $request, Video $video)
    {
        $title = $video->title;

        $this->videoDeletionService->delete($video, $request->boolean('delete_files'), $request->boolean('prevent_redownload'));

        $message = "\"{$title}\" has been deleted.";

        // Only an explicit, same-origin relative path is honored (never an absolute/external
        // URL), so this can only ever redirect within the app. It's set by the kebab menu on
        // a video's own show page, since that page no longer exists once its own video is
        // gone — everywhere else, back() correctly returns to the listing the delete came from.
        $redirectTo = $request->input('redirect_to');
        if (is_string($redirectTo) && str_starts_with($redirectTo, '/') && ! str_starts_with($redirectTo, '//')) {
            return redirect($redirectTo)->with('status', $message);
        }

        return back()->with('status', $message);
    }
}
