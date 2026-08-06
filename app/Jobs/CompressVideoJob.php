<?php

namespace App\Jobs;

use App\Models\Setting;
use App\Models\Video;
use App\Services\FfmpegService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class CompressVideoJob implements ShouldQueue
{
    use Queueable;

    /**
     * Generous ceiling for a single HEVC re-encode. Unlike the download pipeline there's no
     * scheduler tick this needs to stay under, but a hung ffmpeg process still needs to be
     * reliably killed eventually rather than tying up a queue worker forever.
     */
    private const COMPRESS_TIMEOUT_SECONDS = 6 * 3600;

    public function __construct(public Video $video) {}

    public function handle(FfmpegService $ffmpeg): void
    {
        $video = $this->video->fresh();

        if (! $video || ! $video->file_path) {
            return;
        }

        $fullPath = Setting::getStoragePath().'/'.$video->file_path;

        if (! file_exists($fullPath)) {
            $video->update(['compression_status' => 'failed', 'compression_error' => 'Video file not found on disk.']);

            return;
        }

        $video->update(['compression_status' => 'processing', 'compression_progress_percent' => 0]);

        $codec = $ffmpeg->detectVideoCodec($fullPath);

        // Already HEVC: finish without touching the file at all, per the "no changes if already
        // compressed" requirement — just record what we now know about its codec.
        if ($ffmpeg->isHevcCodec($codec)) {
            $video->update([
                'video_codec' => 'hevc',
                'compression_status' => 'completed',
                'compression_progress_percent' => 100,
                'compression_error' => null,
            ]);

            return;
        }

        $tempDir = storage_path('app/temp/'.Str::random(16));
        mkdir($tempDir, 0755, true);

        // Always transcode into an MKV container rather than preserving the source's own
        // extension: some source containers (WebM in particular, which only allows VP8/VP9/AV1
        // video) reject an HEVC stream outright, and ffmpeg fails to even write the header. MKV
        // reliably holds HEVC alongside whatever audio/subtitle codec got copied through as-is.
        $tempOutput = $tempDir.'/compressed.mkv';

        [$success, $error] = $ffmpeg->compressToHevc(
            $fullPath,
            $tempOutput,
            self::COMPRESS_TIMEOUT_SECONDS,
            $this->progressWriter($video)
        );

        if (! $success || ! file_exists($tempOutput)) {
            Log::error("HEVC compression failed for video {$video->youtube_id}: {$error}");
            $video->update([
                'compression_status' => 'failed',
                'compression_error' => Str::limit($error, 2000),
            ]);
            $this->cleanup($tempDir);

            return;
        }

        // If the source wasn't already .mkv, the video's stored path (and the file on disk)
        // moves to a .mkv extension so the MIME type MediaController::show() derives from it
        // (via response()->file(), extension-based) still matches the file's real container.
        $newRelativePath = preg_replace('/\.[^.\/]+$/', '.mkv', $video->file_path);
        $newFullPath = Setting::getStoragePath().'/'.$newRelativePath;

        if (! $this->swapInCompressedFile($fullPath, $newFullPath, $tempOutput)) {
            $message = "Failed to swap in the compressed file for video {$video->youtube_id}; original left untouched.";
            Log::error($message);
            $video->update(['compression_status' => 'failed', 'compression_error' => $message]);
            $this->cleanup($tempDir);

            return;
        }

        $video->update([
            'file_path' => $newRelativePath,
            'video_codec' => 'hevc',
            'file_size' => filesize($newFullPath) ?: null,
            'compression_status' => 'completed',
            'compression_progress_percent' => 100,
            'compression_error' => null,
        ]);

        $this->cleanup($tempDir);
    }

    /**
     * Replace the original file with the compressed one, in a way that never leaves the video
     * unplayable if something goes wrong partway through:
     *
     * 1. Move the original aside (same filesystem as $originalPath, so this is a cheap rename).
     * 2. Copy the compressed file into place at $newPath — which may differ from $originalPath
     *    when the container's extension changed (see the .mkv note in handle()). This has to be
     *    a copy, not a rename, because $tempOutput may be on a different filesystem/mount than
     *    the configured storage path — mirrors DownloadNextVideo's own copy() for the same reason.
     * 3. Only once the copy has succeeded is the set-aside original actually deleted.
     *
     * If the copy fails, any partial file at $newPath is removed and the set-aside original is
     * restored to $originalPath, so the video is never left without a playable file.
     */
    private function swapInCompressedFile(string $originalPath, string $newPath, string $tempOutput): bool
    {
        $backupPath = $originalPath.'.pre-compress.bak';

        if (! @rename($originalPath, $backupPath)) {
            return false;
        }

        if (! @copy($tempOutput, $newPath)) {
            @unlink($newPath);
            @rename($backupPath, $originalPath);

            return false;
        }

        @unlink($backupPath);

        return true;
    }

    /**
     * Build an ffmpeg output callback that keeps $video->compression_progress_percent roughly
     * in sync with the encode's real progress, parsed from `-progress pipe:1`'s `out_time=`
     * lines against the video's known duration. Throttled to a write only every 5 percentage
     * points (or on hitting 100%), same reasoning as DownloadNextVideo's progressWriter().
     */
    private function progressWriter(Video $video): callable
    {
        $lastSavedPercent = -1;
        $duration = $video->duration;

        return function (string $type, string $buffer) use ($video, &$lastSavedPercent, $duration) {
            if (! $duration) {
                return;
            }

            foreach (preg_split('/\r\n|\r|\n/', $buffer) as $line) {
                if (! str_starts_with($line, 'out_time=')) {
                    continue;
                }

                $timeString = trim(substr($line, strlen('out_time=')));

                if (! preg_match('/^(\d+):(\d+):(\d+(?:\.\d+)?)$/', $timeString, $matches)) {
                    continue;
                }

                $seconds = ((int) $matches[1] * 3600) + ((int) $matches[2] * 60) + (float) $matches[3];
                $percent = max(0, min(100, (int) round($seconds / $duration * 100)));

                if (abs($percent - $lastSavedPercent) < 5 && $percent !== 100) {
                    continue;
                }

                $lastSavedPercent = $percent;
                $video->update(['compression_progress_percent' => $percent]);
            }
        };
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
