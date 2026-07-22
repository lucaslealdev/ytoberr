<?php

namespace App\Http\Controllers;

use App\Jobs\UpdateToolsJob;
use App\Models\Setting;
use App\Models\Video;
use App\Models\Warning;
use App\Models\YtDlpCache;
use App\Services\BackupService;
use App\Services\UpdateChecker;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class SettingsController extends Controller
{
    public function index(UpdateChecker $updateChecker, BackupService $backups)
    {
        $storagePath = Setting::getStoragePath();
        $cacheCount = YtDlpCache::count();

        $latestVersion = $updateChecker->latestVersion();
        $updateAvailable = $updateChecker->isNewer(config('app.version'), $latestVersion);

        $ytdlpDelaySeconds = Setting::ytdlpDelaySeconds();
        $advancedModeEnabled = Setting::advancedModeEnabled();
        $lightModeEnabled = Setting::lightModeEnabled();
        $warnings = Warning::with('video')->latest()->get();
        $backupsList = $backups->list();

        $cookiesPath = storage_path('app/cookies.txt');
        $cookiesConfigured = file_exists($cookiesPath);
        $cookiesUpdatedAt = $cookiesConfigured ? Carbon::createFromTimestamp(filemtime($cookiesPath)) : null;

        return view('settings.index', compact(
            'storagePath', 'cacheCount', 'latestVersion', 'updateAvailable',
            'ytdlpDelaySeconds', 'advancedModeEnabled', 'lightModeEnabled', 'warnings', 'backupsList',
            'cookiesConfigured', 'cookiesUpdatedAt'
        ));
    }

    /**
     * Shelling out to yt-dlp just to print its version takes the better part of a second
     * (interpreter/self-extraction startup), which made the Settings page itself feel slow
     * to open. Fetched asynchronously by the page's JS instead of blocking index() above.
     */
    public function ytdlpVersion()
    {
        $ytDlp = config('services.ytdlp_path', base_path('bin/yt-dlp'));

        $version = trim(shell_exec(escapeshellarg($ytDlp).' --version') ?? '');

        return response()->json(['version' => $version !== '' ? $version : 'Unknown']);
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

        $path = rtrim($request->string('storage_path')->trim()->toString(), '/');

        if ($path === '') {
            return back()->withErrors(['storage_path' => 'Enter a valid directory path.']);
        }

        if (! is_dir($path)) {
            // @-suppressed: mkdir() emits an E_WARNING on failure (bad permissions, a parent
            // that's actually a file, etc.) that would otherwise surface as a generic error
            // page instead of the validation message below.
            if (! @mkdir($path, 0755, true)) {
                return back()->withErrors([
                    'storage_path' => 'This directory does not exist and could not be created. Check the path and that the application has permission to create it.',
                ]);
            }
        } elseif (! is_writable($path)) {
            return back()->withErrors([
                'storage_path' => 'This directory exists but is not writable by the application. Check its permissions.',
            ]);
        }

        Setting::set('storage_path', $path);

        return back()->with('status', 'Storage path updated successfully!');
    }

    public function updateYtdlpDelay(Request $request)
    {
        $request->validate([
            'ytdlp_delay_seconds' => ['required', 'integer', 'min:0', 'max:120'],
        ]);

        Setting::set('ytdlp_delay_seconds', (string) $request->ytdlp_delay_seconds);

        return back()->with('status', 'yt-dlp request delay updated successfully!');
    }

    public function updateAdvancedMode(Request $request)
    {
        Setting::set('advanced_mode', $request->boolean('advanced_mode') ? '1' : '0');

        return back()->with('status', 'Advanced mode updated successfully!');
    }

    public function updateLightMode(Request $request)
    {
        Setting::set('light_mode', $request->boolean('light_mode') ? '1' : '0');

        return back()->with('status', 'Theme updated successfully!');
    }

    public function updateCookies(Request $request)
    {
        $request->validate([
            'cookies_file' => ['nullable', 'file'],
            'cookies_text' => ['nullable', 'string'],
        ]);

        if ($request->hasFile('cookies_file')) {
            $content = file_get_contents($request->file('cookies_file')->getRealPath());
        } elseif ($request->filled('cookies_text')) {
            $content = $request->string('cookies_text')->toString();
        } else {
            return back()->withErrors(['cookies_file' => 'Upload a cookies file or paste its contents below.']);
        }

        // Netscape cookie files may be exported with Windows line endings; normalize to LF
        // since yt-dlp runs on Linux here and a mismatched newline format can produce a
        // confusing "HTTP Error 400: Bad Request" instead of a clear parsing error.
        $content = str_replace(["\r\n", "\r"], "\n", $content);

        $firstLine = trim(strtok($content, "\n"));
        if (! in_array($firstLine, ['# HTTP Cookie File', '# Netscape HTTP Cookie File'], true)) {
            return back()->withErrors(['cookies_file' => 'This does not look like a valid Netscape-format cookies file. The first line must be exactly "# HTTP Cookie File" or "# Netscape HTTP Cookie File".']);
        }

        file_put_contents(storage_path('app/cookies.txt'), $content);

        return back()->with('status', 'Cookies saved successfully!');
    }

    public function deleteCookies()
    {
        $path = storage_path('app/cookies.txt');

        if (file_exists($path)) {
            unlink($path);
        }

        return back()->with('status', 'Cookies removed.');
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

    public function deleteWarning(Warning $warning)
    {
        $warning->delete();

        return back()->with('status', 'Warning dismissed.');
    }

    public function clearWarnings()
    {
        $count = Warning::count();

        Warning::query()->delete();

        return back()->with('status', "{$count} warning(s) cleared.");
    }

    public function createBackup(BackupService $backups)
    {
        $filename = $backups->create();

        return back()->with('status', "Backup created: {$filename}");
    }

    public function downloadBackup(string $filename, BackupService $backups)
    {
        $path = $backups->path($filename);

        abort_unless($path, 404);

        return response()->download($path);
    }

    public function deleteBackup(string $filename, BackupService $backups)
    {
        $backups->delete($filename);

        return back()->with('status', 'Backup deleted.');
    }

    public function restoreBackup(string $filename, BackupService $backups)
    {
        $path = $backups->path($filename);

        abort_unless($path, 404);

        $backups->restoreFromPath($path);

        return back()->with('status', "Database restored from backup: {$filename}");
    }

    public function restoreBackupUpload(Request $request, BackupService $backups)
    {
        $request->validate([
            'backup_file' => ['required', 'file'],
        ]);

        $uploadedPath = $request->file('backup_file')->getRealPath();

        if (file_get_contents($uploadedPath, false, null, 0, 16) !== "SQLite format 3\0") {
            return back()->withErrors(['backup_file' => 'This does not look like a valid SQLite database file.']);
        }

        $backups->restoreFromPath($uploadedPath);

        return back()->with('status', 'Database restored from the uploaded file.');
    }
}
