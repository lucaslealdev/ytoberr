<?php

namespace App\Http\Controllers;

use App\Models\Video;
use App\Services\VideoDeletionService;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class CleaningController extends Controller
{
    /**
     * How many of the heaviest videos to surface — enough to make a dent in disk usage
     * without turning this into a full paginated listing of its own.
     */
    private const TOP_VIDEOS_LIMIT = 10;

    public function __construct(private VideoDeletionService $videoDeletionService) {}

    public function index()
    {
        $videos = Video::with('channel')
            ->where('status', 'completed')
            ->whereNotNull('file_size')
            ->orderByDesc('file_size')
            ->limit(self::TOP_VIDEOS_LIMIT)
            ->get();

        return view('cleaning.index', compact('videos'));
    }

    /**
     * Bulk-delete videos selected on the Cleaning page. Shares the same two opt-in choices
     * (and the same underlying VideoDeletionService) as the single-video "Delete Video" modal —
     * see VideoDeletionService::delete() for what each one does.
     */
    public function destroy(Request $request)
    {
        $validated = $request->validate([
            'video_ids' => ['required', 'array', 'min:1'],
            'video_ids.*' => ['integer', 'exists:videos,id'],
        ]);

        $videos = Video::whereIn('id', $validated['video_ids'])->get();
        $deleteFiles = $request->boolean('delete_files');
        $preventRedownload = $request->boolean('prevent_redownload');

        foreach ($videos as $video) {
            $this->videoDeletionService->delete($video, $deleteFiles, $preventRedownload);
        }

        $count = $videos->count();

        return redirect()->route('cleaning.index')->with('status', "{$count} ".Str::plural('video', $count).' deleted.');
    }
}
