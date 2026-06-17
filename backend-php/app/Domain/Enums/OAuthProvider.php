<?php

namespace App\Domain\Enums;

/**
 * Social login providers we support via Laravel Socialite. Kept as an enum
 * so adding Apple later is a one-line change here plus a config block in
 * config/services.php — the rest of the OAuth flow is provider-agnostic.
 * See docs/AUTH-AND-PERMISSIONS.md.
 */
enum OAuthProvider: string
{
    case GOOGLE = 'google';
    case FACEBOOK = 'facebook';

    /** Human label for UI / emails. */
    public function label(): string
    {
        return match ($this) {
            self::GOOGLE => 'Google',
            self::FACEBOOK => 'Facebook',
        };
    }

    /**
     * True when client id + secret are configured in services config —
     * i.e. the provider is "live" rather than dormant.
     */
    public function isConfigured(): bool
    {
        $cfg = config('services.'.$this->value);

        return is_array($cfg)
            && !empty($cfg['client_id'])
            && !empty($cfg['client_secret']);
    }
}
