<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class ResetPasswordCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_resets_existing_tenant_admin_and_revokes_sessions(): void
    {
        $tenant = DB::table('tenants')->insertGetId(['name' => 'Test']);
        $manager = User::factory()->create(['tenant_id' => $tenant, 'role' => 'tenant_admin', 'remember_token' => 'old']);
        DB::table('sessions')->insert(['id' => 'old', 'user_id' => $manager->id, 'payload' => '', 'last_activity' => time()]);

        $this->artisan('lms:reset-password', ['email' => strtoupper($manager->email)])
            ->expectsQuestion('Mật khẩu mới', 'new-test-password')
            ->expectsQuestion('Nhập lại mật khẩu mới', 'different-password')
            ->assertFailed();
        $this->assertSame($manager->password, $manager->fresh()->password);
        $this->assertDatabaseHas('sessions', ['id' => 'old']);

        $this->artisan('lms:reset-password', ['email' => strtoupper($manager->email)])
            ->expectsQuestion('Mật khẩu mới', 'new-test-password')
            ->expectsQuestion('Nhập lại mật khẩu mới', 'new-test-password')
            ->assertSuccessful();
        $this->assertTrue(Hash::check('new-test-password', $manager->fresh()->password));
        $this->assertNull($manager->fresh()->remember_token);
        $this->assertDatabaseMissing('sessions', ['id' => 'old']);
    }

    public function test_reports_when_an_account_cannot_log_in_to_the_client(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $this->artisan('lms:reset-password', ['email' => $admin->email])
            ->expectsOutputToContain('không có quyền đăng nhập Client')
            ->assertFailed();
        $this->assertSame($admin->password, $admin->fresh()->password);
    }
}
