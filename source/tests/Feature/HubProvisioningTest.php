<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class HubProvisioningTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'internal-provisioning-test-secret';

    private static string $publicKey;

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        self::$publicKey = openssl_pkey_get_details($key)['key'];
    }

    protected function setUp(): void
    {
        parent::setUp();
        config(['hub.base_url' => 'https://hub.example', 'hub.provisioning_secret' => self::SECRET, 'hub.initial_password' => 'initial-manager-password']);
    }

    private function payload(array $changes = []): array
    {
        return array_replace([
            'hub_tenant_id' => 53, 'tenant_name' => 'SMB', 'manager_name' => 'SMB manager',
            'manager_email' => 'Manager@example.com',
            'client_id' => 'client-smb', 'deployment_id' => 'deployment-smb',
            'login_url' => 'https://hub.example/lti/login', 'launch_url' => 'https://hub.example/lti/launch',
            'public_key' => self::$publicKey,
        ], $changes);
    }

    public function test_reports_missing_provisioning_settings_without_exposing_the_secret(): void
    {
        config(['hub.base_url' => 'http://localhost', 'hub.provisioning_secret' => 'too-short']);
        $this->postJson('/hub/provision', $this->payload())
            ->assertServiceUnavailable()
            ->assertJson(['message' => 'Client chưa cấu hình nhận tài khoản từ Hub.']);
        $this->assertDatabaseCount('users', 0);
        $this->artisan('lms:hub-provision-status')
            ->expectsOutput('HUB_BASE_URL: thiếu hoặc không phải URL HTTPS hợp lệ')
            ->expectsOutput('HUB_PROVISIONING_SECRET: thiếu hoặc ngắn hơn 32 byte')
            ->assertFailed();

        config(['hub.base_url' => 'https://hub.example', 'hub.provisioning_secret' => self::SECRET]);
        $this->artisan('lms:hub-provision-status')
            ->expectsOutput('HUB_BASE_URL: hợp lệ')
            ->expectsOutput('HUB_PROVISIONING_SECRET: đã cấu hình')
            ->doesntExpectOutputToContain(self::SECRET)
            ->assertSuccessful();
    }

    public function test_hub_provisions_a_manager_that_can_log_in_and_create_a_teacher(): void
    {
        $this->get('/')->assertOk()->assertSee('type="text" name="email"', false);
        $data = $this->payload(['manager_email' => null, 'manager_username' => 'tenant-admin-smb']);
        $this->postJson('/hub/provision', $data)->assertForbidden();
        $this->withToken(self::SECRET)->postJson('/hub/provision', $data)->assertOk()
            ->assertExactJson(['account_synced' => true, 'must_change_password' => true, 'password_issued' => false]);
        $this->postJson('/hub/provision', $data)->assertOk()->assertJson(['must_change_password' => true]);
        $this->assertDatabaseCount('tenants', 1);
        $this->assertDatabaseCount('lti_registrations', 1);
        $this->assertDatabaseCount('users', 1);
        $tenant = DB::table('tenants')->where('hub_tenant_id', 53)->first();
        $this->assertDatabaseHas('lti_registrations', [
            'tenant_id' => $tenant->id, 'client_id' => 'client-smb', 'deployment_id' => 'deployment-smb',
            'deep_link_url' => 'https://hub.example/lti/launch',
        ]);

        $this->post('/login', ['email' => 'TENANT-ADMIN-SMB', 'password' => 'initial-manager-password'])->assertRedirect('/account/password');
        $manager = User::where('username', 'tenant-admin-smb')->firstOrFail();
        $this->assertAuthenticatedAs($manager);
        $this->assertNull($manager->email);
        $this->get('/account/password')->assertOk()->assertSee('Đây là lần đăng nhập đầu tiên.');
        $this->get('/admin/users')->assertRedirect('/account/password');
        $this->post('/admin/users', ['email' => 'should-not-exist@example.com'])->assertRedirect('/account/password');
        $this->post('/account/password', [
            'current_password' => 'initial-manager-password',
            'password' => 'changed-manager-password', 'password_confirmation' => 'changed-manager-password',
        ])->assertRedirect('/');
        $this->assertFalse($manager->fresh()->must_change_password);
        $this->put('/account/profile', ['name' => 'SMB manager', 'email' => ''])->assertRedirect()->assertSessionHasNoErrors();
        $this->withToken(self::SECRET)->postJson('/hub/provision', $data)->assertOk()->assertJson(['must_change_password' => false]);
        $this->assertTrue(Hash::check('changed-manager-password', $manager->fresh()->password));
        $this->post('/admin/users', [
            'email' => 'teacher@example.com', 'name' => 'Teacher', 'role' => 'teacher', 'password' => 'teacher-test-password',
        ])->assertRedirect();
        $this->assertDatabaseHas('users', ['email' => 'teacher@example.com', 'tenant_id' => $tenant->id, 'role' => 'teacher']);
        $this->post('/logout')->assertRedirect('/');
        $this->postJson('/login', ['email' => 'tenant-admin-smb', 'password' => 'initial-manager-password'])->assertUnprocessable();
        $this->post('/login', ['email' => 'tenant-admin-smb', 'password' => 'changed-manager-password'])->assertRedirect('/');
    }

    public function test_conflicts_are_atomic_and_provisioning_does_not_replace_passwords(): void
    {
        $this->withToken(self::SECRET)->postJson('/hub/provision', $this->payload())->assertOk();
        $manager = User::firstOrFail();
        $manager->forceFill(['password' => 'new-manager-password', 'must_change_password' => false])->save();
        $this->postJson('/hub/provision', $this->payload())->assertOk()->assertJson(['account_synced' => true, 'must_change_password' => false]);
        $this->assertTrue(Hash::check('new-manager-password', $manager->fresh()->password));

        $otherTenant = ['hub_tenant_id' => 54, 'tenant_name' => 'TPLUS'];
        $this->postJson('/hub/provision', $this->payload($otherTenant))->assertConflict();
        $this->postJson('/hub/provision', $this->payload($otherTenant + ['client_id' => 'client-tplus']))->assertConflict();
        $this->postJson('/hub/provision', $this->payload(['launch_url' => 'https://other.example/lti/launch']))->assertUnprocessable();
        $this->assertDatabaseCount('tenants', 1);
        $this->assertDatabaseCount('lti_registrations', 1);
        $this->assertDatabaseCount('users', 1);
    }

    public function test_system_admin_uses_hub_and_cannot_log_in_to_client(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'password' => 'admin-test-password']);
        $this->postJson('/login', ['email' => $admin->email, 'password' => 'admin-test-password'])->assertUnprocessable();
        $this->assertGuest();
        $this->actingAs($admin)->get('/admin/tenants')->assertForbidden();
        $this->get('/')->assertRedirect('/');
        $this->assertGuest();
    }

    public function test_existing_deployment_is_linked_to_hub_without_replacing_existing_account_or_tool(): void
    {
        $tenant = DB::table('tenants')->insertGetId(['name' => 'SMB']);
        $manager = User::factory()->create(['role' => 'tenant_admin', 'tenant_id' => $tenant, 'email' => 'manager@example.com']);
        $tool = DB::table('lti_registrations')->insertGetId([
            'tenant_id' => $tenant, 'client_id' => 'client-smb', 'deployment_id' => 'deployment-smb',
            'name' => 'Legacy H5P', 'login_url' => 'https://hub.example/lti/login',
            'redirect_uri' => 'https://hub.example/lti/launch', 'public_key' => self::$publicKey,
        ]);

        $this->withToken(self::SECRET)->postJson('/hub/provision', $this->payload())
            ->assertOk()->assertJson(['account_synced' => true, 'must_change_password' => false]);
        $this->assertDatabaseHas('tenants', ['id' => $tenant, 'hub_tenant_id' => 53]);
        $this->assertDatabaseHas('lti_registrations', ['id' => $tool, 'tenant_id' => $tenant, 'deep_link_url' => 'https://hub.example/lti/launch']);
        $this->assertSame($manager->password, $manager->fresh()->password);
        $this->assertDatabaseCount('tenants', 1);
        $this->assertDatabaseCount('users', 1);
    }

    public function test_requires_client_initial_password_to_create_an_account_and_ignores_hub_password(): void
    {
        config(['hub.initial_password' => null]);
        $data = $this->payload(['password' => 'ignored-legacy-hub-password']);
        $this->withToken(self::SECRET)->postJson('/hub/provision', $data)->assertServiceUnavailable();
        $this->assertDatabaseCount('tenants', 0);
        $this->assertDatabaseCount('users', 0);

        config(['hub.initial_password' => 'initial-manager-password']);
        $this->postJson('/hub/provision', $data)->assertOk();
        $this->assertTrue(Hash::check('initial-manager-password', User::firstOrFail()->password));
    }
}
