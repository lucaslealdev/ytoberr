<?php

namespace App\Http\Controllers;

use App\Models\Setting;
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

        if ($request->boolean('delete_files')) {
            $this->deleteVideoFilesFromDisk($video);
        }

        if ($request->boolean('prevent_redownload')) {
            // status=deleted keeps this row out of every listing/show query (all scoped to
            // status=completed) and out of the download queue (videos:download only pulls
            // status=pending), while the row's continued existence is exactly what stops
            // CheckChannelsForNewVideos from ever treating it as a new candidate again.
            $video->update([
                'status' => 'deleted',
                'file_path' => null,
                'file_size' => null,
                'thumbnail_path' => null,
                'downloaded_at' => null,
                'prevent_download' => true,
                'unavailable_reason' => 'Manually deleted',
            ]);
        } else {
            $video->delete();
        }

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

    /**
     * Best-effort, opt-in removal of a single video's downloaded file, thumbnail and .nfo
     * metadata from disk. Same realpath()-containment technique as
     * ChannelController::deleteChannelFilesFromDisk(): anything that doesn't resolve to a real
     * path inside the configured downloads directory is silently skipped rather than deleted.
     */
    private function deleteVideoFilesFromDisk(Video $video): void
    {
        $downloadsDir = realpath(Setting::getStoragePath());
        if (! $downloadsDir) {
            return;
        }

        $relativePaths = [$video->file_path, $video->thumbnail_path];
        if ($video->file_path) {
            // Plex "Local Media Assets" per-video .nfo shares the video's own filename, just
            // with a .nfo extension instead (see DownloadNextVideo/PlexAssetService::writeVideoNfo).
            $relativePaths[] = preg_replace('/\.[^.\/]+$/', '.nfo', $video->file_path);
        }

        foreach (array_filter($relativePaths) as $relativePath) {
            $fullPath = realpath($downloadsDir.'/'.$relativePath);

            if (! $fullPath || ! str_starts_with($fullPath, $downloadsDir.DIRECTORY_SEPARATOR)) {
                continue;
            }

            if (is_file($fullPath)) {
                unlink($fullPath);
            }
        }
    }
}
