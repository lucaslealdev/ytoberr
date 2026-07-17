<?php

namespace App\Http\Controllers;

class LogsController extends Controller
{
    /**
     * How many of the most recent log entries to show. Older entries are still on disk
     * (subject to normal log rotation/retention) — this is purely a display cap.
     */
    private const MAX_ENTRIES = 200;

    /**
     * Only the tail of the log file is ever read, not the whole thing — an install that's
     * been running a while can easily have a multi-megabyte laravel.log, and this page only
     * ever displays the most recent entries anyway.
     */
    private const TAIL_BYTES = 512 * 1024;

    public function index()
    {
        $path = storage_path('logs/laravel.log');

        return view('logs.index', [
            'entries' => file_exists($path) ? $this->parseEntries($path) : [],
            'logPath' => $path,
            'logSize' => file_exists($path) ? filesize($path) : 0,
        ]);
    }

    /**
     * Empty the log file in place rather than deleting it — Monolog's StreamHandler would
     * happily recreate a missing file on the next write, but truncating keeps the existing
     * file's ownership/permissions intact instead of relying on that recreation matching them.
     */
    public function clear()
    {
        $path = storage_path('logs/laravel.log');

        if (file_exists($path)) {
            file_put_contents($path, '');
        }

        return redirect()->route('logs.index')->with('status', 'Log file cleared.');
    }

    /**
     * Parse Laravel's default single-line-per-entry log format ("[timestamp] channel.LEVEL:
     * message", optionally followed by extra context/stack-trace lines up to the next
     * timestamped line) into structured entries, newest first.
     *
     * @return array<int, array{timestamp: string, level: string, message: string, details: string}>
     */
    private function parseEntries(string $path): array
    {
        $handle = fopen($path, 'rb');

        if (! $handle) {
            return [];
        }

        $size = filesize($path);

        if ($size === 0) {
            fclose($handle);

            return [];
        }

        $readBytes = min($size, self::TAIL_BYTES);
        fseek($handle, -$readBytes, SEEK_END);
        $chunk = fread($handle, $readBytes);
        fclose($handle);

        $lines = preg_split('/\r\n|\r|\n/', $chunk) ?: [];

        $entries = [];
        $current = null;

        foreach ($lines as $line) {
            if (preg_match('/^\[(\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}:\d{2})\]\s+\S+?\.(\w+):\s*(.*)$/', $line, $matches)) {
                if ($current !== null) {
                    $entries[] = $current;
                }

                $current = [
                    'timestamp' => $matches[1],
                    'level' => strtoupper($matches[2]),
                    'message' => $matches[3],
                    'details' => '',
                ];

                continue;
            }

            // Lines before the first matched header are the tail-end of an entry whose own
            // header was cut off by the TAIL_BYTES seek above — there's no way to know its
            // timestamp/level, so they're discarded rather than shown as a headerless entry.
            if ($current !== null && $line !== '') {
                $current['details'] .= ($current['details'] === '' ? '' : "\n").$line;
            }
        }

        if ($current !== null) {
            $entries[] = $current;
        }

        return array_slice(array_reverse($entries), 0, self::MAX_ENTRIES);
    }
}
