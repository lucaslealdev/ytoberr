<?php

namespace Tests\Feature;

use App\Models\Channel;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

class SettingsBackupControllerTest extends TestCase
{
    // Deliberately no RefreshDatabase: it wraps each test in a transaction, and SQLite's
    // VACUUM (used by BackupService::create()) cannot run inside one. Also uses a real
    // database file rather than :memory:, since restoreFromPath() copies a file directly
    // onto config('database.connections.sqlite.database') — not meaningful against ":memory:".
    private string $liveDbPath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->liveDbPath = storage_path('app/settings-backup-controller-test.sqlite');
        if (file_exists($this->liveDbPath)) {
            unlink($this->liveDbPath);
        }
        touch($this->liveDbPath);

        config(['database.default' => 'sqlite']);
        config(['database.connections.sqlite.database' => $this->liveDbPath]);

        $this->artisan('migrate', ['--force' => true]);

        $this->actingAs(User::factory()->create());
    }

    protected function tearDown(): void
    {
        foreach (glob(storage_path('app/backups/*.sqlite')) ?: [] as $file) {
            unlink($file);
        }

        if (file_exists($this->liveDbPath)) {
            unlink($this->liveDbPath);
        }

        parent::tearDown();
    }

    public function test_create_backup_via_http_creates_a_downloadable_file()
    {
        $response = $this->post('/settings/backups');

        $response->assertRedirect();
        $response->assertSessionHas('status');

        $files = glob(storage_path('app/backups/*.sqlite'));
        $this->assertCount(1, $files);
    }

    public function test_can_download_an_existing_backup()
    {
        $this->post('/settings/backups');
        $filename = basename(glob(storage_path('app/backups/*.sqlite'))[0]);

        $response = $this->get("/settings/backups/{$filename}/download");

        $response->assertStatus(200);
    }

    public function test_downloading_a_nonexistent_backup_returns_404()
    {
        $response = $this->get('/settings/backups/ytoberr-backup-2020-01-01-000000-abcdef.sqlite/download');

        $response->assertStatus(404);
    }

    public function test_can_delete_an_existing_backup()
    {
        $this->post('/settings/backups');
        $filename = basename(glob(storage_path('app/backups/*.sqlite'))[0]);

        $response = $this->delete("/settings/backups/{$filename}");

        $response->assertRedirect();
        $this->assertFileDoesNotExist(storage_path('app/backups/'.$filename));
    }

    public function test_can_restore_from_an_existing_backup()
    {
        Channel::create([
            'youtube_id' => 'UC_restore_http_test',
            'name' => 'Restore HTTP Test Channel',
            'url' => 'https://example.com/restore-http-test',
        ]);

        $this->post('/settings/backups');
        $filename = basename(glob(storage_path('app/backups/*.sqlite'))[0]);

        Channel::query()->delete();
        $this->assertSame(0, Channel::count());

        $response = $this->post("/settings/backups/{$filename}/restore");

        $response->assertRedirect();
        $this->assertDatabaseHas('channels', ['name' => 'Restore HTTP Test Channel']);
    }

    public function test_can_restore_from_an_uploaded_backup_file()
    {
        Channel::create([
            'youtube_id' => 'UC_restore_upload_test',
            'name' => 'Restore Upload Test Channel',
            'url' => 'https://example.com/restore-upload-test',
        ]);

        $this->post('/settings/backups');
        $backupPath = glob(storage_path('app/backups/*.sqlite'))[0];

        Channel::query()->delete();
        $this->assertSame(0, Channel::count());

        $response = $this->post('/settings/backups/restore-upload', [
            'backup_file' => new UploadedFile($backupPath, 'my-upload.sqlite', null, null, true),
        ]);

        $response->assertRedirect();
        $this->assertDatabaseHas('channels', ['name' => 'Restore Upload Test Channel']);
    }

    public function test_restoring_from_an_uploaded_file_that_is_not_sqlite_is_rejected()
    {
        $fakeFile = tempnam(sys_get_temp_dir(), 'not-sqlite');
        file_put_contents($fakeFile, 'this is definitely not a sqlite database');

        $response = $this->post('/settings/backups/restore-upload', [
            'backup_file' => new UploadedFile($fakeFile, 'fake.sqlite', null, null, true),
        ]);

        $response->assertSessionHasErrors('backup_file');

        unlink($fakeFile);
    }
}
