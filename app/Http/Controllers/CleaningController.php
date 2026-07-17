<?php

namespace App\Http\Controllers;

use App\Models\Video;
use App\Services\VideoDeletionService;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class CleaningController extends Controller
{
    /**
     * How many of the heaviest videos to surface on the Biggest Videos tab — enough to make a
     * dent in disk usage without turning this into a full paginated listing of its own.
     */
    private const BIGGEST_VIDEOS_LIMIT = 10;

    /**
     * How many of the oldest videos to surface on the Oldest Videos tab. Deliberately larger
     * than BIGGEST_VIDEOS_LIMIT: age alone doesn't correlate with disk usage the way size
     * does, so clearing meaningful space this way usually means removing more of them at once.
     */
    private const OLDEST_VIDEOS_LIMIT = 50;

    public function __construct(private VideoDeletionService $videoDeletionService) {}

    public function index()
    {
        $biggestVideos = Video::with('channel')
            ->where('status', 'completed')
            ->whereNotNull('file_size')
            ->orderByDesc('file_size')
            ->limit(self::BIGGEST_VIDEOS_LIMIT)
            ->get();

        $oldestVideos = Video::with('channel')
            ->where('status', 'completed')
            ->orderBy('published_at')
            ->limit(self::OLDEST_VIDEOS_LIMIT)
            ->get();

        return view('cleaning.index', compact('biggestVideos', 'oldestVideos'));
    }

    /**
     * Bulk-delete videos selected on either Cleaning tab. Shares the same two opt-in choices
     * (and the same underlying VideoDeletionService) as the single-video "Delete Video" modal —
     * see VideoDeletionService::delete() for what each one does.
     */
    public function destroy(Request $request)
    {
        $validated = $request->validate([
            'video_ids' => ['required', 'array', 'min:1'],
            'video_ids.*' => ['integer', 'exists:videos,id'],
            'tab' => ['nullable', 'in:biggest,oldest'],
        ]);

        $videos = Video::whereIn('id', $validated['video_ids'])->get();
        $deleteFiles = $request->boolean('delete_files');
        $preventRedownload = $request->boolean('prevent_redownload');

        foreach ($videos as $video) {
            $this->videoDeletionService->delete($video, $deleteFiles, $preventRedownload);
        }

        $count = $videos->count();

        // Keeps the redirect on whichever tab the delete was triggered from, instead of
        // always bouncing back to the Biggest Videos tab.
        $redirectParams = isset($validated['tab']) ? ['tab' => $validated['tab']] : [];

        return redirect()->route('cleaning.index', $redirectParams)
            ->with('status', "{$count} ".Str::plural('video', $count).' deleted.');
    }
}
