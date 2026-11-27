<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

class HubProvisioningConfig
{
    public static function origin(): string
    {
        return LearningAccess::origin(rtrim((string) config('hub.base_url'), '/'));
    }

    public static function secret(): ?string
    {
        $secret = (string) config('hub.provisioning_secret');

        return strlen($secret) >= 32 ? $secret : null;
    }

    public static function accountTool(int $tenant): ?object
    {
        $origin = self::origin();
        if ($origin === '') {
            return null;
        }

        return DB::table('lti_registrations')->where('tenant_id', $tenant)->orderBy('id')->get()
            ->first(fn ($tool) => LearningAccess::origin($tool->login_url) === $origin
                && LearningAccess::origin($tool->redirect_uri) === $origin);
    }
}
