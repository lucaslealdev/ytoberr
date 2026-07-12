<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EnsureUserExistsTest extends TestCase
{
    use RefreshDatabase;

    public function test_protected_route_redirects_to_setup_when_no_users_exist()
    {
        $response = $this->get('/');

        $response->assertRedirect('/setup');
    }

    public function test_setup_redirects_to_login_when_a_user_already_exists()
    {
        User::factory()->create();

        $response = $this->get('/setup');

        $response->assertRedirect('/login');
    }

    public function test_protected_route_passes_through_normally_when_a_user_exists()
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get('/');

        $response->assertStatus(200);
    }
}
