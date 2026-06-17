<?php

namespace App\Http\Controllers\Api;

use App\Domain\Enums\OAuthProvider;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\OAuthService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
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

        // Login / registration flow.
        $result = $oauth->resolveLogin($resolved, $oauthUser);
        $token = $result['user']->createToken('spa:oauth:'.$resolved->value)->plainTextToken;

        return redirect()->away($spa.'/oauth/callback?token='.urlencode($token));
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

    private function b64url(string $raw): string
    {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }

    private function b64urlDecode(string $enc): string
    {
        return (string) base64_decode(strtr($enc, '-_', '+/'));
    }
}
