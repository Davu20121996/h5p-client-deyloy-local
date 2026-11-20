<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class FormSecurityTest extends TestCase
{
    use RefreshDatabase;

    private function manager(): User
    {
        DB::table('tenants')->insert(['id' => 1, 'name' => 'Security test']);

        return User::factory()->create(['role' => 'tenant_admin', 'tenant_id' => 1]);
    }

    public function test_malformed_email_is_rejected_without_server_error(): void
    {
        $this->actingAs($this->manager())->postJson('/admin/users', [
            'name' => 'Example', 'email' => ['invalid'], 'role' => 'student',
            'password' => 'A-secure-password-123',
        ])->assertUnprocessable()->assertJsonValidationErrors('email');
    }

    public function test_invalid_lock_value_cannot_unlock_an_account(): void
    {
        $manager = $this->manager();
        $student = User::factory()->create(['role' => 'student', 'tenant_id' => 1, 'locked' => true]);
        $this->actingAs($manager)->postJson('/admin/users/'.$student->id.'/lock', [
            'locked' => 'invalid',
        ])->assertUnprocessable()->assertJsonValidationErrors('locked');
        $this->assertTrue((bool) $student->fresh()->locked);
    }

    public function test_login_throttle_cannot_be_bypassed_by_changing_email(): void
    {
        for ($i = 0; $i < 20; $i++) {
            $this->postJson('/login', ['email' => 'attempt'.$i.'@example.com', 'password' => 'invalid'])
                ->assertUnprocessable();
        }
        $this->postJson('/login', ['email' => 'another@example.com', 'password' => 'invalid'])
            ->assertStatus(429);
    }
}
