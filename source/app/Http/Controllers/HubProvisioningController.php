<?php

namespace App\Http\Controllers;

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
        $secret = (string) config('hub.provisioning_secret');
        $hub = rtrim((string) config('hub.base_url'), '/');
        $origin = LearningAccess::origin($hub);
        abort_unless(strlen($secret) >= 32 && $origin, 503);
        abort_unless(hash_equals($secret, (string) $request->bearerToken()), 403);

        $data = $request->validate([
            'hub_tenant_id' => 'required|integer|min:1',
            'tenant_name' => 'required|string|max:255',
            'manager_name' => 'required|string|max:255',
            'manager_email' => 'required|email|max:255',
            'password' => 'required|string|min:12|max:72',
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
            $issued = DB::transaction(function () use ($data, $details, $origin): bool {
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

                $email = strtolower($data['manager_email']);
                $manager = DB::table('users')->whereRaw('lower(email) = ?', [$email])->lockForUpdate()->first();
                if ($manager) {
                    abort_unless((int) $manager->tenant_id === (int) $tenantId && $manager->role === 'tenant_admin' && ! $manager->locked, 409);

                    // Hub may retry a request after the Client has committed but before it receives the reply.
                    return Hash::check($data['password'], $manager->password);
                }
                DB::table('users')->insert([
                    'tenant_id' => $tenantId, 'name' => $data['manager_name'], 'email' => $email,
                    'role' => 'tenant_admin', 'password' => Hash::make($data['password']),
                    'created_at' => now(), 'updated_at' => now(),
                ]);

                return true;
            }, 3);
        } catch (UniqueConstraintViolationException) {
            abort(409);
        }

        return response()->json(['password_issued' => $issued])->header('Cache-Control', 'no-store');
    }
}
