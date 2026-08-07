<?php

namespace App\Services;

class FfmpegService
{
    /**
     * How long an ffprobe codec check is allowed to run before being killed. It only reads a
     * file's stream headers, not the whole file, so this only needs margin over a slow disk.
     */
    private const PROBE_TIMEOUT_SECONDS = 60;

    /**
     * Reuses YtDlpWrapper's process runner (Symfony Process wrapped with the process-group-kill
     * timeout handling needed to reliably terminate ffmpeg/ffprobe, not anything yt-dlp specific)
     * instead of duplicating that logic here.
     */
    public function __construct(private YtDlpWrapper $processRunner) {}

    /**
     * The video stream's codec name (e.g. "hevc", "h264", "vp9"), or null if it couldn't be
     * determined (missing/corrupt file, no video stream, ffprobe failure).
     */
    public function detectVideoCodec(string $filePath): ?string
    {
        return $this->detectStreamCodec($filePath, 'v:0');
    }

    /**
     * The audio stream's codec name (e.g. "aac", "opus", "mp3"), or null if it couldn't be
     * determined (missing/corrupt file, no audio stream, ffprobe failure).
     */
    public function detectAudioCodec(string $filePath): ?string
    {
        return $this->detectStreamCodec($filePath, 'a:0');
    }

    private function detectStreamCodec(string $filePath, string $stream): ?string
    {
        $ffprobe = config('services.ffprobe_path', base_path('bin/ffprobe'));

        $command = "{$ffprobe} -v error -select_streams {$stream} -show_entries stream=codec_name -of default=noprint_wrappers=1:nokey=1 "
            .escapeshellarg($filePath);

        [$output, $resultCode] = $this->processRunner->runCommand($command, self::PROBE_TIMEOUT_SECONDS);

        if ($resultCode !== 0 || empty($output)) {
            return null;
        }

        return strtolower(trim($output[0]));
    }

    public function isHevcCodec(?string $codec): bool
    {
        return in_array($codec, ['hevc', 'h265'], true);
    }

    /**
     * Whether $codec is already efficient enough that a CRF-based HEVC re-encode predictably
     * produces a *larger* file rather than a smaller one — confirmed in practice with VP9/AV1
     * sources, which are already comparable to or more efficient than libx265 at a quality-
     * focused CRF. There's no reasonable expectation of a size win here, so callers should skip
     * the (expensive) transcode attempt entirely rather than run it just to discover that.
     */
    public function isAlreadyEfficientCodec(?string $codec): bool
    {
        return in_array($codec, ['vp9', 'av1'], true);
    }

    /**
     * Transcode $inputPath's video stream to HEVC (H.265) into $outputPath, copying audio/
     * subtitle streams as-is (only the video codec changes).
     *
     * $outputPath must use a container that can actually hold an HEVC stream (e.g. .mkv) —
     * callers must not simply reuse the input's own extension/container, since some source
     * containers (e.g. WebM, which only allows VP8/VP9/AV1 video) reject HEVC outright and
     * ffmpeg fails to even write the header.
     *
     * $onOutput, if given, receives ffmpeg's raw output as it's produced (Symfony's
     * `function (string $type, string $buffer)` signature) — used to track encode progress via
     * ffmpeg's `-progress pipe:1` lines without waiting for the whole encode to finish.
     *
     * @return array{0: bool, 1: string} [success, error output tail (empty on success)]
     */
    public function compressToHevc(string $inputPath, string $outputPath, int $timeoutSeconds, ?callable $onOutput = null): array
    {
        $ffmpeg = config('services.ffmpeg_path', base_path('bin/ffmpeg'));

        $arguments = [
            '-y',
            '-i '.escapeshellarg($inputPath),
            '-map 0',
            '-c:v libx265',
            '-crf 23',
            '-preset medium',
            '-c:a copy',
            '-c:s copy',
            '-progress pipe:1',
            '-nostats',
        ];

        $argumentsString = implode(' ', $arguments);
        $command = "{$ffmpeg} {$argumentsString} ".escapeshellarg($outputPath).' 2>&1';

        [$output, $resultCode] = $this->processRunner->runCommand($command, $timeoutSeconds, $onOutput);

        if ($resultCode !== 0) {
            return [false, implode("\n", array_slice($output, -20))];
        }

        return [true, ''];
    }
}
