<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class MediaControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        $downloadsDir = Setting::getStoragePath();
        if (file_exists($downloadsDir.'/Media Test Channel')) {
            exec('rm -rf '.escapeshellarg($downloadsDir.'/Media Test Channel'));
        }

        parent::tearDown();
    }

    public function test_show_returns_existing_file_with_correct_content_and_content_type()
    {
        $user = User::factory()->create();

        $downloadsDir = Setting::getStoragePath();
        @mkdir($downloadsDir.'/Media Test Channel', 0755, true);

        $relativePath = 'Media Test Channel/note.txt';
        $contents = 'hello media controller test';
        file_put_contents($downloadsDir.'/'.$relativePath, $contents);

        $response = $this->actingAs($user)->get('/media/'.$relativePath);

        $response->assertStatus(200);
        $this->assertStringStartsWith('text/plain', $response->headers->get('Content-Type'));
        $this->assertSame($contents, $response->streamedContent());
    }

    public function test_show_returns_404_for_a_nonexistent_file()
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get('/media/Media Test Channel/does-not-exist.mp4');

        $response->assertStatus(404);
    }

    public function test_path_traversal_attempt_does_not_leak_files_outside_downloads_dir()
    {
        $user = User::factory()->create();

        // Make sure there is a real file to be leaked if the traversal protection failed.
        $this->assertFileExists('/etc/passwd');

        $response = $this->actingAs($user)->get('/media/..%2F..%2F..%2Fetc%2Fpasswd');

        $response->assertStatus(404);
        $response->assertDontSee('root:', false);
    }

    public function test_path_traversal_attempt_with_literal_dots_is_also_blocked()
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get('/media/../../../../../../etc/passwd');

        $response->assertStatus(404);
        $response->assertDontSee('root:', false);
    }

    public function test_show_public_disk_returns_file_with_a_one_week_immutable_cache_header()
    {
        Storage::fake('public');
        Storage::disk('public')->put('channels/1/poster.jpg', 'fake poster bytes');

        $user = User::factory()->create();

        $response = $this->actingAs($user)->get('/channel-media/channels/1/poster.jpg');

        $response->assertStatus(200);
        $this->assertSame('fake poster bytes', $response->streamedContent());
        // Symfony's ResponseHeaderBag normalizes Cache-Control directives alphabetically
        // regardless of the order they were set in.
        $response->assertHeader('Cache-Control', 'immutable, max-age=604800, public');
    }

    public function test_show_public_disk_returns_404_for_a_nonexistent_file()
    {
        Storage::fake('public');

        $user = User::factory()->create();

        $response = $this->actingAs($user)->get('/channel-media/channels/1/does-not-exist.jpg');

        $response->assertStatus(404);
    }

    public function test_show_public_disk_path_traversal_attempt_does_not_leak_files_outside_the_public_disk()
    {
        Storage::fake('public');

        $user = User::factory()->create();

        $response = $this->actingAs($user)->get('/channel-media/..%2F..%2F..%2Fetc%2Fpasswd');

        $response->assertStatus(404);
        $response->assertDontSee('root:', false);
    }
}
