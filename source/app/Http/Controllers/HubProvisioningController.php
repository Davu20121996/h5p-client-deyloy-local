<?php

namespace App\Http\Controllers;

use App\Services\HubProvisioningConfig;
use App\Services\LearningAccess;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class HubProvisioningController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $secret = HubProvisioningConfig::secret();
        $origin = HubProvisioningConfig::origin();
        abort_unless($secret !== null && $origin, 503, 'Client chưa cấu hình nhận tài khoản từ Hub.');
        abort_unless(hash_equals($secret, (string) $request->bearerToken()), 403);

        $data = $request->validate([
            'hub_tenant_id' => 'required|integer|min:1',
            'tenant_name' => 'required|string|max:255',
            'manager_name' => 'required|string|max:255',
            'manager_email' => 'nullable|required_without:manager_username|email|max:255',
            'manager_username' => ['nullable', 'required_without:manager_email', 'string', 'min:3', 'max:64', 'regex:/\A[a-zA-Z0-9][a-zA-Z0-9._-]*\z/'],
            'client_id' => 'required|string|max:255',
            'deployment_id' => 'required|string|max:255',
            'login_url' => 'required|url:https|max:2048',
            'launch_url' => 'required|url:https|max:2048',
            'public_key' => 'required|string|max:8192',
        ]);
        foreach (['login_url', 'launch_url'] as $field) {
            if (LearningAccess::origin($data[$field]) !== $origin) {
                throw ValidationException::withMessages([$field => 'URL phải thuộc Hub đã cấu hình.']);
            }
        }
        $publicKey = openssl_pkey_get_public($data['public_key']);
        $details = $publicKey ? openssl_pkey_get_details($publicKey) : false;
        if (($details['type'] ?? null) !== OPENSSL_KEYTYPE_RSA || $details['bits'] < 2048) {
            throw ValidationException::withMessages(['public_key' => 'Cần khóa công khai RSA từ 2048 bit.']);
        }

        try {
            $mustChangePassword = DB::transaction(function () use ($data, $details, $origin): bool {
                $registration = DB::table('lti_registrations')->where('client_id', $data['client_id'])->lockForUpdate()->first();
                $tenant = DB::table('tenants')->where('hub_tenant_id', $data['hub_tenant_id'])->lockForUpdate()->first();
                if (! $tenant && $registration) {
                    // Adopt only an unlinked tenant whose existing deployment already points to this Hub.
                    abort_unless(LearningAccess::origin($registration->redirect_uri) === $origin, 409);
                    $tenant = DB::table('tenants')->where('id', $registration->tenant_id)
                        ->whereNull('hub_tenant_id')->lockForUpdate()->first();
                    abort_unless($tenant, 409);
                }

                abort_if($registration && ((int) $registration->tenant_id !== (int) $tenant->id
                    || $registration->deployment_id !== $data['deployment_id']), 409);
                $tenantId = $tenant?->id ?? DB::table('tenants')->insertGetId([
                    'hub_tenant_id' => $data['hub_tenant_id'], 'name' => $data['tenant_name'],
                    'created_at' => now(), 'updated_at' => now(),
                ]);
                if ($tenant && ($tenant->hub_tenant_id === null || $tenant->name !== $data['tenant_name'])) {
                    DB::table('tenants')->where('id', $tenantId)->update([
                        'hub_tenant_id' => $data['hub_tenant_id'], 'name' => $data['tenant_name'], 'updated_at' => now(),
                    ]);
                }
                DB::table('lti_registrations')->updateOrInsert(['client_id' => $data['client_id']], [
                    'tenant_id' => $tenantId, 'deployment_id' => $data['deployment_id'],
                    'name' => 'H5P Hub', 'login_url' => $data['login_url'], 'redirect_uri' => $data['launch_url'],
                    'deep_link_url' => $data['launch_url'], 'public_key' => $details['key'],
                ]);

                $email = isset($data['manager_email']) ? strtolower($data['manager_email']) : null;
                $username = isset($data['manager_username']) ? strtolower($data['manager_username']) : null;
                $matches = DB::table('users')->where(function ($query) use ($email, $username) {
                    if ($email !== null) {
                        $query->whereRaw('lower(email) = ?', [$email]);
                    }
                    if ($username !== null) {
                        $query->orWhere('username', $username);
                    }
                })->orderBy('id')->lockForUpdate()->get();
                abort_if($matches->count() > 1, 409);
                $manager = $matches->first();
                if ($manager) {
                    abort_unless((int) $manager->tenant_id === (int) $tenantId && $manager->role === 'tenant_admin' && ! $manager->locked, 409);
                    abort_if($username !== null && $manager->username !== null && $manager->username !== $username, 409);
                    abort_if($email !== null && $manager->email !== null && strtolower($manager->email) !== $email, 409);
                    DB::table('users')->where('id', $manager->id)->update([
                        'username' => $manager->username ?? $username,
                        'email' => $manager->email ?? $email,
                        'updated_at' => now(),
                    ]);

                    return (bool) $manager->must_change_password;
                }
                $password = (string) config('hub.initial_password');
                abort_unless(strlen($password) >= 12 && strlen($password) <= 72, 503, 'Client chưa cấu hình mật khẩu ban đầu cho Tenant Admin.');
                DB::table('users')->insert([
                    'tenant_id' => $tenantId, 'name' => $data['manager_name'], 'email' => $email, 'username' => $username,
                    'role' => 'tenant_admin', 'password' => Hash::make($password), 'must_change_password' => true,
                    'created_at' => now(), 'updated_at' => now(),
                ]);

                return true;
            }, 3);
        } catch (UniqueConstraintViolationException) {
            abort(409);
        }

        return response()->json([
            'account_synced' => true,
            'must_change_password' => $mustChangePassword,
            'password_issued' => false,
        ])->header('Cache-Control', 'no-store');
    }
}
