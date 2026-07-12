<?php

namespace App\Http\Controllers;

use App\Models\Setting;

class MediaController extends Controller
{
    /**
     * Stream a file from the configured downloads directory. The downloads
     * directory can live anywhere on disk (not necessarily under the public
     * disk), so files are served directly instead of relying on the
     * storage:link symlink.
     */
    public function show(string $path)
    {
        $baseDir = realpath(Setting::getStoragePath());
        if (!$baseDir) {
            abort(404);
        }

        $fullPath = realpath($baseDir . '/' . $path);

        if (!$fullPath || !str_starts_with($fullPath, $baseDir . DIRECTORY_SEPARATOR) || !is_file($fullPath)) {
            abort(404);
        }

        return response()->file($fullPath);
    }
}
