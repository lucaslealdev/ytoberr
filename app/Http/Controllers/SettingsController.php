<?php

namespace App\Http\Controllers;

use App\Jobs\UpdateToolsJob;
use App\Models\Setting;
use App\Models\Video;
use App\Models\YtDlpCache;
use App\Services\UpdateChecker;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class SettingsController extends Controller
{
    public function index(UpdateChecker $updateChecker)
    {
        $ytDlp = base_path('bin/yt-dlp');

        $ytDlpVersion = shell_exec(escapeshellarg($ytDlp).' --version');

        $storagePath = Setting::getStoragePath();
        $cacheCount = YtDlpCache::count();

        // Videos currently in queue (status is not completed)
        $queuedVideos = Video::with('channel')
            ->where('status', '!=', 'completed')
            ->orderBy('created_at', 'desc')
            ->get();

        $latestVersion = $updateChecker->latestVersion();
        $updateAvailable = $updateChecker->isNewer(config('app.version'), $latestVersion);

        return view('settings.index', compact(
            'ytDlpVersion', 'storagePath', 'cacheCount', 'queuedVideos', 'latestVersion', 'updateAvailable'
        ));
    }

    public function updateProfile(Request $request)
    {
        $user = $request->user();

        $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users,email,'.$user->id],
            'password' => ['nullable', 'string', 'min:8', 'confirmed'],
        ]);

        $user->name = $request->name;
        $user->email = $request->email;

        if ($request->filled('password')) {
            $user->password = Hash::make($request->password);
        }

        $user->save();

        return back()->with('status', 'Profile updated successfully!');
    }

    public function updateTools()
    {
        UpdateToolsJob::dispatch();

        return back()->with('status', 'Update process started in the background!');
    }

    public function updateStoragePath(Request $request)
    {
        $request->validate([
            'storage_path' => ['required', 'string'],
        ]);

        Setting::set('storage_path', $request->storage_path);

        return back()->with('status', 'Storage path updated successfully!');
    }

    public function checkMissingVideos()
    {
        $missingVideos = [];
        $videos = Video::with('channel')->get();
        $downloadsDir = Setting::getStoragePath();

        foreach ($videos as $video) {
            if (empty($video->file_path)) {
                continue;
            }

            // O file_path deve ser relativo ao downloadsDir
            $fullPath = $downloadsDir.'/'.ltrim($video->file_path, '/');
            $exists = file_exists($fullPath);

            if (! $exists) {
                $missingVideos[] = [
                    'id' => $video->id,
                    'title' => $video->title,
                    'channel' => $video->channel->name ?? 'Unknown',
                    'file_path' => $video->file_path,
                ];
            }
        }

        return response()->json($missingVideos);
    }

    public function cleanMissingVideos(Request $request)
    {
        $videos = Video::all();
        $downloadsDir = Setting::getStoragePath();
        $deletedCount = 0;

        foreach ($videos as $video) {
            if (empty($video->file_path)) {
                continue;
            }

            $fullPath = $downloadsDir.'/'.ltrim($video->file_path, '/');
            $exists = file_exists($fullPath);

            if (! $exists) {
                $video->delete();
                $deletedCount++;
            }
        }

        return back()->with('status', "Removed {$deletedCount} missing video records from the database!");
    }

    public function resetCache()
    {
        YtDlpCache::truncate();

        return back()->with('status', 'yt-dlp metadata cache cleared successfully!');
    }
}
