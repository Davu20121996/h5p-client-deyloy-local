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
        return $this->provision($request, false);
    }

    public function account(Request $request): JsonResponse
    {
        return $this->provision($request, true);
    }

    private function provision(Request $request, bool $withSubject): JsonResponse
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
            'manager_username' => ['nullable', $withSubject ? 'required' : 'required_without:manager_email', 'string', 'min:3', 'max:64', 'regex:/\A(?![0-9]+\z)[a-zA-Z0-9][a-zA-Z0-9._-]*\z/'],
            'account_role' => $withSubject ? 'required|in:teacher,tenant_admin' : 'prohibited',
            'client_id' => 'required|string|max:255',
            'deployment_id' => 'required|string|max:255',
            'login_url' => 'required|url:https|max:2048',
            'launch_url' => 'required|url:https|max:2048',
            'public_key' => 'required|string|max:8192',
        ]);
        $role = $data['account_role'] ?? 'tenant_admin';
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
            $account = DB::transaction(function () use ($data, $details, $origin, $role): array {
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
                $user = $matches->first();
                if ($user) {
                    abort_unless((int) $user->tenant_id === (int) $tenantId && $user->role === $role && ! $user->locked, 409);
                    abort_if($username !== null && $user->username !== null && $user->username !== $username, 409);
                    abort_if($email !== null && $user->email !== null && strtolower($user->email) !== $email, 409);
                    DB::table('users')->where('id', $user->id)->update([
                        'username' => $user->username ?? $username,
                        'email' => $user->email ?? $email,
                        'updated_at' => now(),
                    ]);

                    return ['subject' => (string) $user->id, 'must_change_password' => (bool) $user->must_change_password];
                }
                $password = (string) config('hub.initial_password');
                abort_unless(strlen($password) >= 12 && strlen($password) <= 72, 503, 'Client chưa cấu hình mật khẩu ban đầu.');
                $userId = DB::table('users')->insertGetId([
                    'tenant_id' => $tenantId, 'name' => $data['manager_name'], 'email' => $email, 'username' => $username,
                    'role' => $role, 'password' => Hash::make($password), 'must_change_password' => true,
                    'created_at' => now(), 'updated_at' => now(),
                ]);

                return ['subject' => (string) $userId, 'must_change_password' => true];
            }, 3);
        } catch (UniqueConstraintViolationException) {
            abort(409);
        }

        return response()->json([
            'account_synced' => true,
            'must_change_password' => $account['must_change_password'],
            'password_issued' => false,
        ] + ($withSubject ? ['subject' => $account['subject'], 'role' => $role] : []))->header('Cache-Control', 'no-store');
    }
}
