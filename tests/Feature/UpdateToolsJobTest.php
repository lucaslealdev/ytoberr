<?php

namespace Tests\Feature;

use App\Jobs\UpdateToolsJob;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class UpdateToolsJobTest extends TestCase
{
    /**
     * Regression test for a bug where UpdateToolsJob::handle() called the bare
     * `Log::info(...)` without importing Illuminate\Support\Facades\Log. Because
     * the job class lives in the App\Jobs namespace, PHP resolved that bare
     * reference to the non-existent class App\Jobs\Log and threw a fatal Error
     * on every run — *after* `exec('make setup-bins')` had already executed.
     * That meant clicking "Check for Updates & Update" in Settings always
     * downloaded the binaries and then blew up, and Laravel's default queue
     * retry (3 attempts) could run the download up to 3 times for nothing.
     */
    public function test_handle_does_not_throw_class_not_found_for_log(): void
    {
        Log::shouldReceive('info')
            ->once()
            ->with('Tool updates initiated via make setup-bins.');

        // Hide `make` from PATH so the real `exec('make setup-bins')` call
        // fails instantly instead of downloading real yt-dlp/ffmpeg binaries
        // over the network. exec() never throws on a missing/failing command,
        // so this doesn't change whether the bug reproduces — it only avoids
        // the slow, network-dependent side effect while still exercising the
        // exact same code path (including the Log::info() call right after).
        $originalPath = getenv('PATH');
        putenv('PATH=' . sys_get_temp_dir());

        try {
            $job = new UpdateToolsJob();
            $job->handle();
        } catch (\Error $e) {
            $this->fail(
                'UpdateToolsJob::handle() threw a fatal Error - the missing '
                . '`use Illuminate\\Support\\Facades\\Log;` import has likely '
                . 'regressed: ' . $e->getMessage()
            );
        } finally {
            putenv('PATH=' . $originalPath);
        }
    }
}
