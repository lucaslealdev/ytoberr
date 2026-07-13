<?php

namespace Tests\Feature;

use App\Models\Channel;
use App\Services\BackupService;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class BackupServiceTest extends TestCase
{
    // Deliberately no RefreshDatabase: it wraps each test in a transaction, and SQLite's
    // VACUUM (including VACUUM INTO) cannot run inside one. The one test that needs real
    // migrated tables (restore) sets up its own isolated database file instead.

    protected function tearDown(): void
    {
        foreach (glob(storage_path('app/backups/*.sqlite')) ?: [] as $file) {
            unlink($file);
        }

        parent::tearDown();
    }

    public function test_create_produces_a_uniquely_named_backup_file()
    {
        $filename = (new BackupService)->create();

        $this->assertMatchesRegularExpression(
            '/^ytoberr-backup-\d{4}-\d{2}-\d{2}-\d{6}-[a-zA-Z0-9]{6}\.sqlite$/',
            $filename
        );
        $this->assertFileExists(storage_path('app/backups/'.$filename));
    }

    public function test_create_twice_in_a_row_produces_two_distinct_files()
    {
        // Regression guard: VACUUM INTO fails outright if the target file already
        // exists, so two backups requested within the same second must not collide.
        $service = new BackupService;

        $first = $service->create();
        $second = $service->create();

        $this->assertNotEquals($first, $second);
        $this->assertFileExists(storage_path('app/backups/'.$first));
        $this->assertFileExists(storage_path('app/backups/'.$second));
    }

    public function test_list_returns_backups_newest_first_with_size_and_date()
    {
        $service = new BackupService;

        $older = $service->create();
        touch(storage_path('app/backups/'.$older), now()->subMinute()->timestamp);

        $newer = $service->create();

        $list = $service->list();

        $this->assertCount(2, $list);
        $this->assertEquals($newer, $list[0]['name']);
        $this->assertEquals($older, $list[1]['name']);
        $this->assertGreaterThan(0, $list[0]['size']);
    }

    public function test_path_returns_null_for_a_filename_that_does_not_exist()
    {
        $this->assertNull((new BackupService)->path('ytoberr-backup-2020-01-01-000000-abcdef.sqlite'));
    }

    public function test_path_rejects_filenames_that_do_not_match_the_expected_format()
    {
        // Guards against path traversal / arbitrary filesystem access via the filename
        // parameter, which flows here from user-facing download/delete/restore routes.
        $service = new BackupService;

        $this->assertNull($service->path('../../etc/passwd'));
        $this->assertNull($service->path('not-a-real-backup.sqlite'));
        $this->assertNull($service->path('ytoberr-backup-2020-01-01-000000.sqlite')); // missing random suffix
    }

    public function test_delete_removes_an_existing_backup_and_returns_true()
    {
        $service = new BackupService;
        $filename = $service->create();

        $this->assertTrue($service->delete($filename));
        $this->assertFileDoesNotExist(storage_path('app/backups/'.$filename));
    }

    public function test_delete_returns_false_for_a_nonexistent_backup()
    {
        $this->assertFalse((new BackupService)->delete('ytoberr-backup-2020-01-01-000000-abcdef.sqlite'));
    }

    public function test_restore_from_path_replaces_the_live_database_and_reruns_migrations()
    {
        $liveDbPath = storage_path('app/test-live-db.sqlite');
        if (file_exists($liveDbPath)) {
            unlink($liveDbPath);
        }
        touch($liveDbPath);

        config(['database.connections.sqlite.database' => $liveDbPath]);
        DB::purge('sqlite');
        $this->artisan('migrate', ['--force' => true]);

        try {
            $service = new BackupService;

            Channel::create([
                'youtube_id' => 'UC_backup_test',
                'name' => 'Backup Test Channel',
                'url' => 'https://example.com/backup-test',
            ]);

            $backupFilename = $service->create();
            $backupPath = storage_path('app/backups/'.$backupFilename);

            // Mutate the live DB after the backup was taken, to prove restore actually
            // reverts to the backed-up state rather than being a no-op.
            Channel::query()->delete();
            $this->assertSame(0, Channel::count());

            $service->restoreFromPath($backupPath);

            $this->assertDatabaseHas('channels', ['name' => 'Backup Test Channel']);
        } finally {
            unlink($liveDbPath);
        }
    }
}
