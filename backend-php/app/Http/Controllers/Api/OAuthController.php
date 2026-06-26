<?php

namespace App\Http\Controllers\Api;

use App\Domain\Enums\OAuthProvider;
use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Services\OAuthService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Laravel\Socialite\Facades\Socialite;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Social login (Google / Facebook) via Laravel Socialite.
 *
 * The SPA uses a full-page navigation: a button sends the browser to the
 * `redirect` route, the provider bounces back to `callback`, and we then
 * redirect into the SPA carrying a freshly-minted Sanctum token (login) or
 * a "?linked=" flag (account linking from the profile screen).
 *
 * DORMANT until client id/secret are configured (see config/services.php +
 * .deploy-secrets): unconfigured providers return 404 and the SPA hides the
 * buttons (it reads GET /auth/providers). Stateless Socialite is used
 * because the API has no session (SESSION_DRIVER=array); linking integrity
 * is carried by our own HMAC-signed `state`. See docs/AUTH-AND-PERMISSIONS.md.
 */
class OAuthController extends Controller
{
    /** GET /api/v1/auth/oauth/{provider}/redirect — bounce to the provider. */
    public function redirect(Request $request, string $provider): RedirectResponse
    {
        $resolved = $this->resolveConfigured($provider);

        $driver = Socialite::driver($resolved->value)->stateless();
        $state = (string) $request->query('state', '');
        if ($state !== '') {
            $driver = $driver->with(['state' => $state]);
        }

        return $driver->redirect();
    }

    /** GET /api/v1/auth/oauth/{provider}/callback — handle the provider's reply. */
    public function callback(Request $request, string $provider, OAuthService $oauth): RedirectResponse
    {
        $resolved = $this->resolveConfigured($provider);
        $spa = rtrim((string) config('app.url'), '/');

        try {
            $oauthUser = Socialite::driver($resolved->value)->stateless()->user();
        } catch (\Throwable) {
            return redirect()->away($spa.'/login?oauth_error=1');
        }

        // Linking flow: a signed state carries the logged-in user's id.
        $linkUserId = $this->verifyLinkState((string) $request->query('state', ''));
        if ($linkUserId !== null) {
            $user = User::find($linkUserId);
            if ($user === null) {
                return redirect()->away($spa.'/login?oauth_error=1');
            }
            try {
                $oauth->link($user, $resolved, $oauthUser);
            } catch (\Throwable) {
                return redirect()->away($spa.'/profil?oauth_link_error='.$resolved->value);
            }

            return redirect()->away($spa.'/profil?linked='.$resolved->value);
        }

        // Login flow: existing social user (known identity or matching
        // email) → straight in.
        $user = $oauth->attemptLogin($resolved, $oauthUser);
        if ($user !== null) {
            $token = $user->createToken('spa:oauth:'.$resolved->value)->plainTextToken;

            return redirect()->away($spa.'/oauth/callback?token='.urlencode($token));
        }

        // Brand-new person → DO NOT create the account yet. Carry the
        // (provider-verified) profile in a short-lived signed token to the
        // SPA's GDPR consent screen; the account is created only after the
        // consents are accepted (POST /auth/oauth/complete).
        $profile = $this->signProfile([
            'provider' => $resolved->value,
            'pid' => (string) $oauthUser->getId(),
            'email' => $oauthUser->getEmail(),
            'name' => $oauthUser->getName() ?: $oauthUser->getNickname(),
        ]);

        return redirect()->away($spa.'/oauth/consent?profile='.urlencode($profile));
    }

    /**
     * POST /api/v1/auth/oauth/complete
     *
     * Finalises a first-time social registration after the user accepted the
     * GDPR consents. Body: { profile (signed token from the callback),
     * privacyAck (must be accepted), dataConsent (optional) }. Creates the
     * PENDING account + identity and logs the user in.
     */
    public function complete(Request $request, OAuthService $oauth): JsonResponse
    {
        $data = $request->validate([
            'profile' => ['required', 'string'],
            'privacyAck' => ['accepted'],
            'dataConsent' => ['nullable', 'boolean'],
        ], [
            'privacyAck.accepted' => 'Pre registráciu musíte potvrdiť oboznámenie s podmienkami spracúvania osobných údajov.',
        ]);

        $profile = $this->verifyProfile($data['profile']);
        if ($profile === null) {
            throw ValidationException::withMessages([
                'profile' => 'Registrácia cez sociálnu sieť vypršala. Skúste sa prihlásiť znova.',
            ]);
        }

        $provider = OAuthProvider::tryFrom((string) ($profile['provider'] ?? ''));
        if ($provider === null) {
            throw ValidationException::withMessages(['profile' => 'Neplatný poskytovateľ.']);
        }

        $user = $oauth->completeRegistration(
            $provider,
            (string) $profile['pid'],
            $profile['email'] ?? null,
            $profile['name'] ?? null,
            ['privacyAck' => true, 'dataConsent' => (bool) ($data['dataConsent'] ?? true)],
        );

        $token = $user->createToken('spa:oauth:'.$provider->value)->plainTextToken;

        return new JsonResponse([
            'token' => $token,
            'user' => (new UserResource($user))->toArray($request),
        ], 201);
    }

    /**
     * GET /api/v1/profile/oauth/{provider}/link-url — (auth) returns the
     * redirect URL with a signed state so the callback links the new
     * identity to THIS user instead of logging in.
     */
    public function linkUrl(Request $request, string $provider): JsonResponse
    {
        $resolved = $this->resolveConfigured($provider);
        /** @var User $user */
        $user = $request->user();

        $state = $this->signLinkState($user->id);
        $url = url('/api/v1/auth/oauth/'.$resolved->value.'/redirect').'?state='.urlencode($state);

        return new JsonResponse(['url' => $url]);
    }

    private function resolveConfigured(string $provider): OAuthProvider
    {
        $resolved = OAuthProvider::tryFrom($provider);
        if ($resolved === null || !$resolved->isConfigured()) {
            throw new NotFoundHttpException('OAuth provider not available.');
        }

        return $resolved;
    }

    private function signLinkState(string $userId): string
    {
        $payload = $this->b64url(json_encode(['uid' => $userId, 'exp' => time() + 600]));

        return $payload.'.'.hash_hmac('sha256', $payload, (string) config('app.key'));
    }

    private function verifyLinkState(string $state): ?string
    {
        if ($state === '' || !str_contains($state, '.')) {
            return null;
        }
        [$payload, $sig] = explode('.', $state, 2);
        $expected = hash_hmac('sha256', $payload, (string) config('app.key'));
        if (!hash_equals($expected, $sig)) {
            return null;
        }
        $data = json_decode($this->b64urlDecode($payload), true);
        if (!is_array($data) || (int) ($data['exp'] ?? 0) < time()) {
            return null;
        }

        return isset($data['uid']) ? (string) $data['uid'] : null;
    }

    /** Sign a first-time OAuth profile (15-min TTL) for the consent gate. */
    private function signProfile(array $profile): string
    {
        $profile['exp'] = time() + 900;
        $payload = $this->b64url((string) json_encode($profile));

        return $payload.'.'.hash_hmac('sha256', $payload, (string) config('app.key'));
    }

    /** @return array<string,mixed>|null */
    private function verifyProfile(string $token): ?array
    {
        if ($token === '' || !str_contains($token, '.')) {
            return null;
        }
        [$payload, $sig] = explode('.', $token, 2);
        $expected = hash_hmac('sha256', $payload, (string) config('app.key'));
        if (!hash_equals($expected, $sig)) {
            return null;
        }
        $data = json_decode($this->b64urlDecode($payload), true);
        if (!is_array($data) || (int) ($data['exp'] ?? 0) < time() || empty($data['pid'])) {
            return null;
        }

        return $data;
    }

    private function b64url(string $raw): string
    {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }

    private function b64urlDecode(string $enc): string
    {
        return (string) base64_decode(strtr($enc, '-_', '+/'));
    }
}
