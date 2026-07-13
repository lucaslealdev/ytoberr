<?php

namespace App\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class BackupService
{
    // Trailing random suffix guarantees a unique filename even if two backups are
    // requested within the same second — VACUUM INTO fails outright if its target
    // file already exists.
    private const FILENAME_PATTERN = '/^ytoberr-backup-\d{4}-\d{2}-\d{2}-\d{6}-[a-zA-Z0-9]{6}\.sqlite$/';

    /**
     * Create a consistent snapshot of the live SQLite database via VACUUM INTO —
     * safe to run while the app is actively reading/writing — and return its filename.
     */
    public function create(): string
    {
        $filename = 'ytoberr-backup-'.now()->format('Y-m-d-His').'-'.Str::random(6).'.sqlite';
        $path = $this->backupDir().'/'.$filename;

        DB::statement('VACUUM INTO ?', [$path]);

        return $filename;
    }

    /**
     * @return Collection<int, array{name: string, size: int, created_at: Carbon}>
     */
    public function list(): Collection
    {
        $files = glob($this->backupDir().'/*.sqlite') ?: [];

        return collect($files)
            ->map(fn (string $path) => [
                'name' => basename($path),
                'size' => filesize($path),
                'created_at' => Carbon::createFromTimestamp(filemtime($path)),
            ])
            ->sortByDesc('created_at')
            ->values();
    }

    /**
     * Full path to an existing backup file, or null if it doesn't exist or the
     * filename doesn't match the format create() produces.
     */
    public function path(string $filename): ?string
    {
        if (! preg_match(self::FILENAME_PATTERN, $filename)) {
            return null;
        }

        $path = $this->backupDir().'/'.$filename;

        return file_exists($path) ? $path : null;
    }

    public function delete(string $filename): bool
    {
        $path = $this->path($filename);

        return $path && unlink($path);
    }

    /**
     * Replace the live database with the contents at $sourcePath (an existing backup,
     * or an uploaded file), then bring the schema up to date and have the queue worker
     * reconnect — otherwise the live PDO connection (this request) and the long-running
     * queue:work process could keep operating against the old file's cached state.
     */
    public function restoreFromPath(string $sourcePath): void
    {
        DB::purge('sqlite');

        copy($sourcePath, $this->liveDatabasePath());

        Artisan::call('migrate', ['--force' => true]);
        Artisan::call('queue:restart');
    }

    private function backupDir(): string
    {
        $dir = storage_path('app/backups');

        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        return $dir;
    }

    private function liveDatabasePath(): string
    {
        return config('database.connections.sqlite.database');
    }
}
