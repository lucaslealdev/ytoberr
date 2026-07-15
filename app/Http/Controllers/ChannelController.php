<?php

namespace App\Http\Controllers;

use App\Jobs\CheckChannelForNewVideosJob;
use App\Models\Channel;
use App\Models\Setting;
use App\Services\ChannelService;
use App\Services\YtDlpWrapper;
use App\Support\PlexNaming;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class ChannelController extends Controller
{
    public function index(Request $request)
    {
        $sort = $request->query('sort', 'name');
        $query = Channel::query();

        switch ($sort) {
            case 'recent_video':
                $query->leftJoin('videos', 'channels.id', '=', 'videos.channel_id')
                    ->select('channels.*')
                    ->groupBy('channels.id')
                    ->orderByRaw('MAX(videos.published_at) DESC');
                break;
            case 'created_at':
                $query->orderBy('created_at', 'desc');
                break;
            case 'name':
            default:
                $query->orderBy('name', 'asc');
                break;
        }

        $channels = $query->with('videos')->paginate(10)->withQueryString();

        return view('channels.index', compact('channels', 'sort'));
    }

    public function store(Request $request)
    {
        $request->validate([
            'url' => 'required|url',
        ]);

        $url = $request->url;

        // Use YtDlpWrapper to safely fetch metadata using selective templates and cache results
        $wrapper = app(YtDlpWrapper::class);
        $data = $wrapper->getMetadata($url, ['channel', 'uploader', 'channel_id', 'thumbnails'], ['--playlist-items 0', '-J']);

        if (! $data) {
            return redirect('/channels')->withErrors(['url' => 'Could not fetch channel information.']);
        }

        $channelName = $data['channel'] ?? $data['uploader'] ?? 'Unknown';
        $channelId = $data['channel_id'] ?? 'unknown_'.uniqid();
        $localThumbnailPath = null;

        $existingChannel = Channel::where('youtube_id', $channelId)->first();
        if ($existingChannel) {
            return redirect('/channels')->withErrors([
                'url' => "This channel is already registered as \"{$existingChannel->name}\".",
            ]);
        }

        try {
            $channel = Channel::create([
                'youtube_id' => $channelId,
                'name' => $channelName,
                'url' => $url,
                'profile_image_path' => $localThumbnailPath,
                'download_quality' => '720p',
                'description' => $data['description'] ?? null,
            ]);

            // Now handle avatar/banner/fanart using new service.
            // This service now handles everything and stores it in channels/<id>/
            Log::info("Fetching and storing channel images for {$channelName} ({$channelId})");
            app(ChannelService::class)->fetchAndStoreChannelImages($channel);

            // Update channel with the poster path if it exists
            $posterPath = 'channels/'.$channel->id.'/poster.jpg';
            if (Storage::disk('public')->exists($posterPath)) {
                $channel->update(['profile_image_path' => $posterPath]);
            }

            Log::info("Finished fetching and storing channel images for {$channelName} ({$channelId})");

            CheckChannelForNewVideosJob::dispatch($channel);

        } catch (\Exception $e) {

            Log::error('Database error while creating channel: '.$e->getMessage());

            return redirect('/channels')->withErrors(['url' => 'Database error: '.$e->getMessage()]);
        }

        return redirect('/channels');
    }

    public function destroy(Request $request, Channel $channel)
    {
        if ($request->boolean('delete_files')) {
            $this->deleteChannelFilesFromDisk($channel);
        }

        $channel->delete();

        return redirect('/channels');
    }

    /**
     * Best-effort, opt-in removal of a channel's downloaded files and stored images from disk.
     *
     * Resolves the channel folder exactly as PlexAssetService/DownloadNextVideo do,
     * then verifies (via realpath containment, same technique as MediaController::show())
     * that the resolved folder is actually inside the configured downloads directory
     * before deleting anything. Any failure to establish that containment (missing
     * directory, symlink escape, etc.) results in a silent no-op rather than an error —
     * the channel DB record deletion should never be blocked by this cleanup step.
     */
    private function deleteChannelFilesFromDisk(Channel $channel): void
    {
        // Internally stored channel art (poster/banner/fanart): both what our own UI displays
        // and the source PlexAssetService copies into the Plex show folder.
        Storage::disk('public')->deleteDirectory('channels/'.$channel->id);

        $downloadsDir = realpath(Setting::getStoragePath());
        if (! $downloadsDir) {
            return;
        }

        $safeChannelName = PlexNaming::sanitize($channel->name);
        $channelDir = realpath($downloadsDir.'/'.$safeChannelName);
        if (! $channelDir) {
            return;
        }

        if (! str_starts_with($channelDir, $downloadsDir.DIRECTORY_SEPARATOR)) {
            return;
        }

        if (is_dir($channelDir)) {
            File::deleteDirectory($channelDir);
        }
    }

    public function show(Request $request, Channel $channel)
    {
        $videoSort = $request->query('video_sort', 'newest');
        $query = $channel->videos()->where('status', 'completed');

        switch ($videoSort) {
            case 'oldest':
                $query->orderBy('published_at', 'asc');
                break;
            case 'title':
                $query->orderBy('title', 'asc');
                break;
            case 'newest':
            default:
                $query->orderBy('published_at', 'desc');
                break;
        }

        $videos = $query->paginate(10)->withQueryString();

        return view('channels.show', compact('channel', 'videos', 'videoSort'));
    }

    public function updateSettings(Request $request, Channel $channel)
    {
        $request->validate([
            'cutoff_date' => ['required', 'date'],
            'quality' => ['required', 'in:480p,720p,1080p'],
        ]);

        $channel->update([
            'cutoff_date' => $request->cutoff_date,
            'download_quality' => $request->quality,
            'download_shorts' => $request->boolean('download_shorts'),
        ]);

        if ($request->wantsJson()) {
            return response()->json([
                'message' => 'Channel settings updated successfully!',
                'channel' => $channel->only(['id', 'cutoff_date', 'download_quality', 'download_shorts']),
            ]);
        }

        return back()->with('status', 'Channel settings updated successfully!');
    }

    /**
     * Queue a background check for new videos on this channel. The check itself can take
     * a long time (yt-dlp network calls plus the configured inter-request delay), so it
     * must run on the queue worker rather than in the request/response cycle, which would
     * otherwise tie up a web server worker until it finished.
     */
    public function checkNewVideos(Channel $channel)
    {
        CheckChannelForNewVideosJob::dispatch($channel);

        return response()->json(['queued' => true]);
    }
}
