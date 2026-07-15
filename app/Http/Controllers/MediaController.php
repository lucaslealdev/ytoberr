<?php

namespace App\Http\Controllers;

use App\Models\Setting;
use Illuminate\Support\Facades\Storage;
use League\Flysystem\FilesystemException;

class MediaController extends Controller
{
    /**
     * How long browsers may cache a channel image without revalidating. Channel
     * poster/banner/fanart images are written once by ChannelService when the channel is
     * added and never overwritten afterward, so a long, non-revalidating cache lifetime is
     * safe here.
     */
    private const PUBLIC_DISK_CACHE_SECONDS = 604800; // 1 week

    /**
     * Stream a file from the configured downloads directory. The downloads
     * directory can live anywhere on disk (not necessarily under the public
     * disk), so files are served directly instead of relying on the
     * storage:link symlink.
     */
    public function show(string $path)
    {
        $baseDir = realpath(Setting::getStoragePath());
        if (! $baseDir) {
            abort(404);
        }

        $fullPath = realpath($baseDir.'/'.$path);

        if (! $fullPath || ! str_starts_with($fullPath, $baseDir.DIRECTORY_SEPARATOR) || ! is_file($fullPath)) {
            abort(404);
        }

        return response()->file($fullPath);
    }

    /**
     * Stream a file from the "public" disk (channel poster/banner/fanart images) with a
     * long-lived Cache-Control header.
     *
     * These files are normally served directly via the storage:link symlink under
     * public/storage, bypassing Laravel (and thus any cache headers) entirely — the app runs
     * on PHP's built-in development server (routes/../server.php, no nginx/Apache in front of
     * it), which silently drops any headers a router script sets once it falls through to its
     * own static-file handling. Routing these through Laravel instead is what actually lets a
     * Cache-Control header reach the browser.
     */
    public function showPublicDisk(string $path)
    {
        // Flysystem itself rejects a path containing ".." segments by throwing
        // PathTraversalDetected rather than just reporting the path as missing, so that has to
        // be caught here and turned into the same plain 404 a genuinely-missing file gets.
        try {
            $exists = Storage::disk('public')->exists($path);
        } catch (FilesystemException) {
            abort(404);
        }

        if (! $exists) {
            abort(404);
        }

        return Storage::disk('public')->response($path, null, [
            'Cache-Control' => 'public, max-age='.self::PUBLIC_DISK_CACHE_SECONDS.', immutable',
        ]);
    }
}
