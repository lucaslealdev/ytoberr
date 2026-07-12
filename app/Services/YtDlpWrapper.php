<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class YtDlpWrapper
{
    /**
     * Retrieve metadata for a given URL using yt-dlp.
     * Uses --print-to-file for selective video metadata to prevent stdout pollution.
     * Falls back to standard output parsing for full dump (-J) with braces bounding cleanup.
     *
     * @param string $url Target YouTube URL (video or channel)
     * @param array $fields Specific JSON fields to retrieve (only for print-to-file)
     * @param array $extraArgs Additional yt-dlp CLI arguments
     * @return array|null Parsed metadata array or null on failure
     */
    public function getMetadata(string $url, array $fields = [], array $extraArgs = []): ?array
    {
        // 0. Cache check
        $cacheKey = md5($url . serialize($fields) . serialize($extraArgs));
        $cached = \App\Models\YtDlpCache::where('key', $cacheKey)
            ->where('expires_at', '>', now())
            ->first();

        if ($cached) {
            Log::info("🚀 [YtDlpWrapper] CACHE HIT! Using cached metadata for key {$cacheKey} (URL: {$url})");
            return $cached->value;
        }

        Log::info("🔌 [YtDlpWrapper] CACHE MISS! Querying yt-dlp for URL: {$url}");

        $ytDlp = config('services.ytdlp_path', base_path('bin/yt-dlp'));
        $isDumpJson = in_array('-J', $extraArgs) || in_array('--dump-json', $extraArgs) || in_array('--print-json', $extraArgs);

        if ($isDumpJson) {
            // Full JSON dump (-J / --dump-json / --print-json)
            $arguments = [];
            foreach ($extraArgs as $arg) {
                $arguments[] = $arg;
            }
            
            $cookiePath = storage_path('app/cookies.txt');
            if (file_exists($cookiePath)) {
                $arguments[] = '--cookies ' . escapeshellarg($cookiePath);
            }

            $argumentsString = implode(' ', $arguments);
            $command = "{$ytDlp} {$argumentsString} " . escapeshellarg($url) . " 2>&1";

            Log::info("YtDlpWrapper running full dump: " . $command);
            
            $output = [];
            $resultCode = 0;
            exec($command, $output, $resultCode);

            if ($resultCode !== 0) {
                Log::error("YtDlpWrapper full dump failed with code {$resultCode}");
                Log::error("YtDlpWrapper raw output: " . implode("\n", $output));
                return null;
            }

            $jsonContent = implode("\n", $output);

            // Clear warnings/logs before or after JSON (bounding between first '{' and last '}')
            $firstBrace = strpos($jsonContent, '{');
            $lastBrace = strrpos($jsonContent, '}');
            if ($firstBrace === false || $lastBrace === false) {
                Log::error("YtDlpWrapper full dump did not contain valid JSON boundaries.");
                return null;
            }
            $jsonContent = substr($jsonContent, $firstBrace, $lastBrace - $firstBrace + 1);

            $metadata = json_decode($jsonContent, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                Log::error("YtDlpWrapper failed to decode full dump JSON: " . json_last_error_msg());
                return null;
            }
        } else {
            // Selective Video metadata using --print-to-file
            $tempDir = storage_path('app/temp/' . Str::random(16));
            mkdir($tempDir, 0755, true);

            $jsonPath = $tempDir . '/metadata.json';
            
            if (empty($fields)) {
                $fields = [
                    'id', 'title', 'description', 'duration', 'upload_date', 
                    'was_live', 'live_status', 'view_count', 'like_count'
                ];
            }

            // Build selective JSON template: %(.{field1,field2,...})j
            $fieldsString = implode(',', $fields);
            $template = "%(.{{$fieldsString}})j";

            $arguments = [
                '--skip-download',
                '--print-to-file',
                escapeshellarg($template) . ' ' . escapeshellarg($jsonPath),
            ];

            foreach ($extraArgs as $arg) {
                $arguments[] = $arg;
            }

            $cookiePath = storage_path('app/cookies.txt');
            if (file_exists($cookiePath)) {
                $arguments[] = '--cookies ' . escapeshellarg($cookiePath);
            }

            $argumentsString = implode(' ', $arguments);
            $command = "{$ytDlp} {$argumentsString} " . escapeshellarg($url) . " 2>&1";

            Log::info("YtDlpWrapper running print-to-file: " . $command);
            
            $output = [];
            $resultCode = 0;
            exec($command, $output, $resultCode);

            if ($resultCode !== 0) {
                Log::error("YtDlpWrapper print-to-file failed with exit code {$resultCode}");
                Log::error("YtDlpWrapper raw output: " . implode("\n", $output));
                $this->cleanup($tempDir);
                return null;
            }

            if (!file_exists($jsonPath)) {
                Log::error("YtDlpWrapper expected output file was not created: {$jsonPath}");
                $this->cleanup($tempDir);
                return null;
            }

            $jsonContent = file_get_contents($jsonPath);
            
            $metadata = json_decode($jsonContent, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                Log::error("YtDlpWrapper failed to decode print-to-file JSON: " . json_last_error_msg());
                Log::error("YtDlpWrapper raw file prefix (500 chars): " . substr($jsonContent, 0, 500));
                $this->cleanup($tempDir);
                return null;
            }

            $this->cleanup($tempDir);
        }

        // Store in database cache with a TTL of 30 minutes
        \App\Models\YtDlpCache::updateOrCreate(
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
            exec("rm -rf " . escapeshellarg($dir));
        }
    }
}
