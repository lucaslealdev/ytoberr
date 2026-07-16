<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LogsControllerTest extends TestCase
{
    use RefreshDatabase;

    private string $logPath;

    private ?string $originalLogContent = null;

    private bool $logFileExistedBefore = false;

    protected function setUp(): void
    {
        parent::setUp();

        // The controller reads the application's real storage/logs/laravel.log — back up
        // whatever's there (this same test run has likely already written to it) and restore
        // it in tearDown, so this test never leaves the real log file altered.
        $this->logPath = storage_path('logs/laravel.log');
        $this->logFileExistedBefore = file_exists($this->logPath);
        if ($this->logFileExistedBefore) {
            $this->originalLogContent = file_get_contents($this->logPath);
        }
    }

    protected function tearDown(): void
    {
        if ($this->logFileExistedBefore) {
            file_put_contents($this->logPath, $this->originalLogContent);
        } elseif (file_exists($this->logPath)) {
            unlink($this->logPath);
        }

        parent::tearDown();
    }

    public function test_logs_page_requires_advanced_mode()
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get('/logs');

        $response->assertRedirect('/settings');
    }

    public function test_logs_page_parses_and_displays_recent_entries_newest_first()
    {
        Setting::set('advanced_mode', '1');
        $user = User::factory()->create();

        file_put_contents($this->logPath, implode("\n", [
            '[2026-07-15 10:00:00] local.INFO: First message',
            '[2026-07-15 10:05:00] local.ERROR: Second message',
            'Stack trace line 1',
            'Stack trace line 2',
        ])."\n");

        $response = $this->actingAs($user)->get('/logs');

        $response->assertStatus(200);
        $response->assertSee('First message');
        $response->assertSee('Second message');
        $response->assertSee('ERROR');
        $response->assertSee('INFO');

        $content = $response->getContent();
        $this->assertTrue(strpos($content, 'Second message') < strpos($content, 'First message'), 'Newest entry should be listed first.');
    }

    public function test_logs_page_shows_multiline_details_behind_a_details_toggle()
    {
        Setting::set('advanced_mode', '1');
        $user = User::factory()->create();

        file_put_contents($this->logPath,
            "[2026-07-15 10:05:00] local.ERROR: Something broke\n".
            "#0 /app/Foo.php(12): bar()\n".
            "#1 {main}\n"
        );

        $response = $this->actingAs($user)->get('/logs');

        $response->assertStatus(200);
        $response->assertSee('Something broke');
        $response->assertSee('#0 /app/Foo.php(12): bar()', false);
        $response->assertSee('View details');
    }

    public function test_logs_page_shows_empty_state_when_no_log_file_exists()
    {
        Setting::set('advanced_mode', '1');
        $user = User::factory()->create();

        if (file_exists($this->logPath)) {
            unlink($this->logPath);
        }

        $response = $this->actingAs($user)->get('/logs');

        $response->assertStatus(200);
        $response->assertSee('No log entries found.');
    }

    public function test_logs_page_handles_a_large_log_file_and_still_shows_the_most_recent_entry()
    {
        Setting::set('advanced_mode', '1');
        $user = User::factory()->create();

        // Enough padding to exceed the controller's tail-read window (512KB), so this also
        // exercises that a large log file doesn't break the page or hide the newest entry.
        $padding = str_repeat("[2026-01-01 00:00:00] local.INFO: padding line\n", 20000);
        file_put_contents($this->logPath, $padding.'[2026-07-15 12:00:00] local.WARNING: Recent entry'."\n");

        $response = $this->actingAs($user)->get('/logs');

        $response->assertStatus(200);
        $response->assertSee('Recent entry');
        $response->assertSee('WARNING');
    }

    public function test_clear_logs_empties_the_log_file()
    {
        Setting::set('advanced_mode', '1');
        $user = User::factory()->create();

        file_put_contents($this->logPath, "[2026-07-15 10:05:00] local.ERROR: Something broke\n");
        $this->assertGreaterThan(0, filesize($this->logPath));

        $response = $this->actingAs($user)->delete('/logs');

        $response->assertRedirect(route('logs.index'));
        $response->assertSessionHas('status', 'Log file cleared.');
        $this->assertSame(0, filesize($this->logPath));
    }

    public function test_clear_logs_requires_advanced_mode()
    {
        $user = User::factory()->create();

        file_put_contents($this->logPath, "[2026-07-15 10:05:00] local.ERROR: Something broke\n");

        $response = $this->actingAs($user)->delete('/logs');

        $response->assertRedirect('/settings');
        $this->assertGreaterThan(0, filesize($this->logPath));
    }

    public function test_clear_logs_button_only_shows_when_there_are_entries()
    {
        Setting::set('advanced_mode', '1');
        $user = User::factory()->create();

        if (file_exists($this->logPath)) {
            unlink($this->logPath);
        }

        $emptyResponse = $this->actingAs($user)->get('/logs');
        $emptyResponse->assertDontSee('Clear Logs');

        file_put_contents($this->logPath, "[2026-07-15 10:05:00] local.ERROR: Something broke\n");

        $populatedResponse = $this->actingAs($user)->get('/logs');
        $populatedResponse->assertSee('Clear Logs');
    }

    public function test_sidebar_shows_logs_link_only_when_advanced_mode_is_enabled()
    {
        $user = User::factory()->create();

        $withoutAdvanced = $this->actingAs($user)->get('/channels');
        $withoutAdvanced->assertDontSee('📋 Logs', false);

        Setting::set('advanced_mode', '1');

        $withAdvanced = $this->actingAs($user)->get('/channels');
        $withAdvanced->assertSee('📋 Logs', false);
    }
}
