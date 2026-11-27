<?php

namespace App\Services;

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
}
