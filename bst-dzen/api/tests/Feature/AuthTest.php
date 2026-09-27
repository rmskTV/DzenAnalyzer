<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_gets_401_on_protected_endpoints(): void
    {
        $this->getJson('/api/auth/me')->assertUnauthorized();
        $this->getJson('/api/channels')->assertUnauthorized();
        $this->getJson('/api/settings')->assertUnauthorized();
        $this->getJson('/api/system-status')->assertUnauthorized();
    }

    public function test_health_stays_public(): void
    {
        $this->getJson('/api/health')->assertOk()->assertJsonPath('status', 'ok');
    }

    public function test_login_returns_user(): void
    {
        $user = User::factory()->create(['is_admin' => true]);

        $this->postJson('/api/auth/login', [
            'email' => $user->email,
            'password' => 'password',
        ])
            ->assertOk()
            ->assertJsonPath('id', $user->id)
            ->assertJsonPath('email', $user->email)
            ->assertJsonPath('is_admin', true);
    }

    public function test_login_fails_with_wrong_password(): void
    {
        $user = User::factory()->create();

        $this->postJson('/api/auth/login', [
            'email' => $user->email,
            'password' => 'wrong',
        ])->assertUnprocessable();
    }

    public function test_login_is_throttled(): void
    {
        $user = User::factory()->create();

        foreach (range(1, 6) as $_) {
            $this->postJson('/api/auth/login', [
                'email' => $user->email,
                'password' => 'wrong',
            ]);
        }

        $this->postJson('/api/auth/login', [
            'email' => $user->email,
            'password' => 'wrong',
        ])->assertTooManyRequests();
    }

    public function test_me_returns_authenticated_user(): void
    {
        $user = Sanctum::actingAs(User::factory()->create(['is_admin' => false]));

        $this->getJson('/api/auth/me')
            ->assertOk()
            ->assertJsonPath('id', $user->id)
            ->assertJsonPath('is_admin', false);
    }

    public function test_logout_clears_state(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->postJson('/api/auth/logout')->assertOk();
    }
}
