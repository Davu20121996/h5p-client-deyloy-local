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
        config(['hub.base_url' => 'https://hub.example', 'hub.provisioning_secret' => self::SECRET]);
    }

    private function payload(array $changes = []): array
    {
        return array_replace([
            'hub_tenant_id' => 53, 'tenant_name' => 'SMB', 'manager_name' => 'SMB manager',
            'manager_email' => 'Manager@example.com', 'password' => 'initial-manager-password',
            'client_id' => 'client-smb', 'deployment_id' => 'deployment-smb',
            'login_url' => 'https://hub.example/lti/login', 'launch_url' => 'https://hub.example/lti/launch',
            'public_key' => self::$publicKey,
        ], $changes);
    }

    public function test_hub_provisions_a_manager_that_can_log_in_and_create_a_teacher(): void
    {
        $data = $this->payload();
        $this->postJson('/hub/provision', $data)->assertForbidden();
        $this->withToken(self::SECRET)->postJson('/hub/provision', $data)->assertOk()->assertExactJson(['password_issued' => true]);
        $this->postJson('/hub/provision', $data)->assertOk()->assertExactJson(['password_issued' => true]);
        $this->assertDatabaseCount('tenants', 1);
        $this->assertDatabaseCount('lti_registrations', 1);
        $this->assertDatabaseCount('users', 1);
        $tenant = DB::table('tenants')->where('hub_tenant_id', 53)->first();
        $this->assertDatabaseHas('lti_registrations', [
            'tenant_id' => $tenant->id, 'client_id' => 'client-smb', 'deployment_id' => 'deployment-smb',
            'deep_link_url' => 'https://hub.example/lti/launch',
        ]);

        $this->post('/login', ['email' => $data['manager_email'], 'password' => $data['password']])->assertRedirect('/');
        $manager = User::where('email', 'manager@example.com')->firstOrFail();
        $this->assertAuthenticatedAs($manager);
        $this->post('/admin/users', [
            'email' => 'teacher@example.com', 'name' => 'Teacher', 'role' => 'teacher', 'password' => 'teacher-test-password',
        ])->assertRedirect();
        $this->assertDatabaseHas('users', ['email' => 'teacher@example.com', 'tenant_id' => $tenant->id, 'role' => 'teacher']);
    }

    public function test_conflicts_are_atomic_and_provisioning_does_not_replace_passwords(): void
    {
        $this->withToken(self::SECRET)->postJson('/hub/provision', $this->payload())->assertOk();
        $manager = User::firstOrFail();
        $manager->forceFill(['password' => 'new-manager-password'])->save();
        $this->postJson('/hub/provision', $this->payload())->assertOk()->assertExactJson(['password_issued' => false]);
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
            ->assertOk()->assertExactJson(['password_issued' => false]);
        $this->assertDatabaseHas('tenants', ['id' => $tenant, 'hub_tenant_id' => 53]);
        $this->assertDatabaseHas('lti_registrations', ['id' => $tool, 'tenant_id' => $tenant, 'deep_link_url' => 'https://hub.example/lti/launch']);
        $this->assertSame($manager->password, $manager->fresh()->password);
        $this->assertDatabaseCount('tenants', 1);
        $this->assertDatabaseCount('users', 1);
    }
}
