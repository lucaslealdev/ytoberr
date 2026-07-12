<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LoginThrottleTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_is_throttled_after_five_invalid_attempts()
    {
        User::factory()->create([
            'email' => 'admin@example.com',
        ]);

        $credentials = [
            'email' => 'admin@example.com',
            'password' => 'wrong-password',
        ];

        for ($i = 1; $i <= 5; $i++) {
            $response = $this->post('/login', $credentials);
            $response->assertStatus(302);
        }

        $response = $this->post('/login', $credentials);
        $response->assertStatus(429);
    }
}
