<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LightModeTest extends TestCase
{
    use RefreshDatabase;

    public function test_light_mode_is_disabled_by_default()
    {
        $this->assertFalse(Setting::lightModeEnabled());
    }

    public function test_can_toggle_light_mode_from_settings()
    {
        $user = User::factory()->create();

        $this->actingAs($user)->post('/settings/light-mode', ['light_mode' => '1']);
        $this->assertTrue(Setting::lightModeEnabled());

        $this->actingAs($user)->post('/settings/light-mode', []);
        $this->assertFalse(Setting::lightModeEnabled());
    }

    public function test_settings_page_shows_the_light_mode_checkbox_reflecting_current_state()
    {
        $user = User::factory()->create();

        $disabledResponse = $this->actingAs($user)->get('/settings');
        $disabledResponse->assertSee('name="light_mode"', false);
        $disabledResponse->assertDontSee('name="light_mode" value="1" checked', false);

        Setting::set('light_mode', '1');

        $enabledResponse = $this->actingAs($user)->get('/settings');
        $enabledResponse->assertSee('name="light_mode" value="1" checked', false);
    }

    public function test_authenticated_pages_apply_the_light_mode_class_when_enabled()
    {
        $user = User::factory()->create();

        $withoutLightMode = $this->actingAs($user)->get('/');
        $withoutLightMode->assertDontSee('class="h-full light-mode"', false);

        Setting::set('light_mode', '1');

        $withLightMode = $this->actingAs($user)->get('/');
        $withLightMode->assertSee('class="h-full light-mode"', false);
    }

    public function test_login_page_applies_the_light_mode_class_when_enabled()
    {
        // The login page redirects to /setup when no user exists yet (EnsureUserExists
        // middleware) — a user has to exist for /login itself to actually render.
        User::factory()->create();

        $withoutLightMode = $this->get('/login');
        $withoutLightMode->assertDontSee('class="light-mode"', false);

        Setting::set('light_mode', '1');

        $withLightMode = $this->get('/login');
        $withLightMode->assertSee('class="light-mode"', false);
    }
}
