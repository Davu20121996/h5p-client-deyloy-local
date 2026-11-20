<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LogoutTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->app->bind(ValidateCsrfToken::class, fn ($app) => new class($app, $app['encrypter']) extends ValidateCsrfToken {
            protected function runningUnitTests() { return false; }
        });
    }

    public function test_expired_session_logout_returns_to_login(): void
    {
        $this->post('/logout', ['_token' => 'expired-token'])->assertRedirect('/')->assertSessionHas('status');
        $this->assertGuest();
    }

    public function test_invalid_token_does_not_log_out_active_session(): void
    {
        $user = User::factory()->create(['role' => 'admin']);
        $this->actingAs($user)->withSession(['_token' => 'valid-token'])
            ->post('/logout', ['_token' => 'old-token'])->assertRedirect('/');
        $this->assertAuthenticatedAs($user);
    }

    public function test_valid_logout_ends_session(): void
    {
        $user = User::factory()->create(['role' => 'admin']);
        $this->actingAs($user)->withSession(['_token' => 'valid-token'])
            ->post('/logout', ['_token' => 'valid-token'])->assertRedirect('/');
        $this->assertGuest();
    }
}
