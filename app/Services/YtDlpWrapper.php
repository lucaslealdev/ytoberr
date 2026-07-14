<?php

namespace App\Services;

use App\Models\Setting;
use App\Models\YtDlpCache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;

class YtDlpWrapper
{
    /**
     * How long a single-item yt-dlp metadata fetch (channel info or a live_status precheck)
     * is allowed to run before being killed. Both are small, single-URL requests, so this only
     * needs generous margin over normal network latency, not room for a large listing.
     */
    private const METADATA_TIMEOUT_SECONDS = 90;

    /**
     * Run a yt-dlp shell command with a hard timeout that actually kills the process (and any
     * children it spawned) if it runs too long. PHP's exec() can't do this: once started, a
     * hung/slow child process keeps running even after the caller (e.g. a queued job) gives up
     * on it, leaking an orphaned yt-dlp process that competes with the next attempt for
     * network/CPU and makes it more likely to time out too.
     *
     * @return array{0: array<int, string>, 1: int} [output lines, exit code]
     */
    public function runCommand(string $command, int $timeoutSeconds): array
    {
        // The leading "exec" makes the shell replace itself with the command instead of
        // forking a child for it (Symfony only adds this automatically for array-form
        // commands, not the shell-string form used here) — without it, Symfony's tracked PID
        // is just the wrapper shell, one level removed from the actual yt-dlp process.
        $process = Process::fromShellCommandline('exec '.$command);
        $process->setTimeout($timeoutSeconds);
        $process->start();

        // Move the process into its own new process group (separate from PHP's own), so that
        // on timeout we can kill that whole group — including any subprocess yt-dlp itself
        // spawns (e.g. ffmpeg, to merge formats) — without touching PHP's own process.
        // Symfony's own kill only signals the single directly-tracked PID, which isn't enough:
        // a child that process forks doesn't die with it and is left running as an orphan.
        if ($pid = $process->getPid()) {
            posix_setpgid($pid, $pid);
        }

        try {
            $process->wait();
        } catch (ProcessTimedOutException) {
            if ($pid) {
                posix_kill(-$pid, SIGKILL);
            }

            Log::error("yt-dlp command timed out after {$timeoutSeconds}s and was killed: {$command}");

            return [["Command timed out after {$timeoutSeconds} seconds."], 124];
        }

        $output = preg_split('/\r\n|\r|\n/', rtrim($process->getOutput()), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        return [$output, $process->getExitCode() ?? 1];
    }

    /**
     * Retrieve metadata for a given URL using yt-dlp.
     * Uses --print-to-file for selective video metadata to prevent stdout pollution.
     * Falls back to standard output parsing for full dump (-J) with braces bounding cleanup.
     *
     * @param  string  $url  Target YouTube URL (video or channel)
     * @param  array  $fields  Specific JSON fields to retrieve (only for print-to-file)
     * @param  array  $extraArgs  Additional yt-dlp CLI arguments
     * @return array|null Parsed metadata array or null on failure
     */
    public function getMetadata(string $url, array $fields = [], array $extraArgs = []): ?array
    {
        // 0. Cache check
        $cacheKey = md5($url.serialize($fields).serialize($extraArgs));
        $cached = YtDlpCache::where('key', $cacheKey)
            ->where('expires_at', '>', now())
            ->first();

        if ($cached) {
            Log::info("🚀 [YtDlpWrapper] CACHE HIT! Using cached metadata for key {$cacheKey} (URL: {$url})");

            return $cached->value;
        }

        Log::info("🔌 [YtDlpWrapper] CACHE MISS! Querying yt-dlp for URL: {$url}");

        $ytDlp = config('services.ytdlp_path', base_path('bin/yt-dlp'));
        $isDumpJson = in_array('-J', $extraArgs) || in_array('--dump-json', $extraArgs) || in_array('--print-json', $extraArgs);

        $delay = Setting::ytdlpDelaySeconds();

        if ($isDumpJson) {
            // Full JSON dump (-J / --dump-json / --print-json)
            $arguments = [];
            foreach ($extraArgs as $arg) {
                $arguments[] = $arg;
            }

            if ($delay > 0) {
                $arguments[] = '--sleep-requests '.$delay;
            }

            $cookiePath = storage_path('app/cookies.txt');
            if (file_exists($cookiePath)) {
                $arguments[] = '--cookies '.escapeshellarg($cookiePath);
            }

            $argumentsString = implode(' ', $arguments);
            $command = "{$ytDlp} {$argumentsString} ".escapeshellarg($url).' 2>&1';

            Log::info('YtDlpWrapper running full dump: '.$command);

            [$output, $resultCode] = $this->runCommand($command, self::METADATA_TIMEOUT_SECONDS);

            if ($resultCode !== 0) {
                Log::error("YtDlpWrapper full dump failed with code {$resultCode}");
                Log::error('YtDlpWrapper raw output: '.implode("\n", $output));

                return null;
            }

            $jsonContent = implode("\n", $output);

            // Clear warnings/logs before or after JSON (bounding between first '{' and last '}')
            $firstBrace = strpos($jsonContent, '{');
            $lastBrace = strrpos($jsonContent, '}');
            if ($firstBrace === false || $lastBrace === false) {
                Log::error('YtDlpWrapper full dump did not contain valid JSON boundaries.');

                return null;
            }
            $jsonContent = substr($jsonContent, $firstBrace, $lastBrace - $firstBrace + 1);

            $metadata = json_decode($jsonContent, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                Log::error('YtDlpWrapper failed to decode full dump JSON: '.json_last_error_msg());

                return null;
            }
        } else {
            // Selective Video metadata using --print-to-file
            $tempDir = storage_path('app/temp/'.Str::random(16));
            mkdir($tempDir, 0755, true);

            $jsonPath = $tempDir.'/metadata.json';

            if (empty($fields)) {
                $fields = [
                    'id', 'title', 'description', 'duration', 'upload_date',
                    'was_live', 'live_status', 'view_count', 'like_count',
                ];
            }

            // Build selective JSON template: %(.{field1,field2,...})j
            $fieldsString = implode(',', $fields);
            $template = "%(.{{$fieldsString}})j";

            $arguments = [
                '--skip-download',
                '--print-to-file',
                escapeshellarg($template).' '.escapeshellarg($jsonPath),
            ];

            foreach ($extraArgs as $arg) {
                $arguments[] = $arg;
            }

            if ($delay > 0) {
                $arguments[] = '--sleep-requests '.$delay;
            }

            $cookiePath = storage_path('app/cookies.txt');
            if (file_exists($cookiePath)) {
                $arguments[] = '--cookies '.escapeshellarg($cookiePath);
            }

            $argumentsString = implode(' ', $arguments);
            $command = "{$ytDlp} {$argumentsString} ".escapeshellarg($url).' 2>&1';

            Log::info('YtDlpWrapper running print-to-file: '.$command);

            [$output, $resultCode] = $this->runCommand($command, self::METADATA_TIMEOUT_SECONDS);

            if ($resultCode !== 0) {
                Log::error("YtDlpWrapper print-to-file failed with exit code {$resultCode}");
                Log::error('YtDlpWrapper raw output: '.implode("\n", $output));
                $this->cleanup($tempDir);

                return null;
            }

            if (! file_exists($jsonPath)) {
                Log::error("YtDlpWrapper expected output file was not created: {$jsonPath}");
                $this->cleanup($tempDir);

                return null;
            }

            $jsonContent = file_get_contents($jsonPath);

            $metadata = json_decode($jsonContent, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                Log::error('YtDlpWrapper failed to decode print-to-file JSON: '.json_last_error_msg());
                Log::error('YtDlpWrapper raw file prefix (500 chars): '.substr($jsonContent, 0, 500));
                $this->cleanup($tempDir);

                return null;
            }

            $this->cleanup($tempDir);
        }

        // Store in database cache with a TTL of 30 minutes
        YtDlpCache::updateOrCreate(
            ['key' => $cacheKey],
            [
                'value' => $metadata,
                'expires_at' => now()->addMinutes(30),
            ]
        );

        return $metadata;
    }

    /**
     * Clean up temporary directory and files
     */
    private function cleanup(string $dir): void
    {
        if (file_exists($dir)) {
            exec('rm -rf '.escapeshellarg($dir));
        }
    }
}
