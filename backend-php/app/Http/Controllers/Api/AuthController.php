<?php

namespace App\Http\Controllers\Api;

use App\Domain\Enums\OAuthProvider;
use App\Domain\Enums\UserRole;
use App\Exceptions\InvalidCredentialsException;
use App\Http\Controllers\Controller;
use App\Http\Requests\ForgotPasswordRequest;
use App\Http\Requests\LoginRequest;
use App\Http\Requests\RegisterRequest;
use App\Http\Requests\ResetPasswordRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Services\AdminNotifier;
use App\Services\CaptchaService;
use App\Services\PasswordResetService;
use App\Services\UsersService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    /**
     * POST /api/v1/auth/login
     *
     * Looks up the user by email, verifies the password and issues a new
     * Sanctum personal-access token. The token's plaintext is returned in
     * the response — that's the only chance the client has to read it, so
     * the SPA stores it immediately. Subsequent calls send it as a
     * `Authorization: Bearer …` header.
     *
     * Returns 401 with INVALID_CREDENTIALS for: unknown email, wrong
     * password, OR an inactive account. We deliberately return the same
     * error in all three cases so an attacker can't enumerate which
     * emails exist.
     */
    public function login(LoginRequest $request): JsonResponse
    {
        $credentials = $request->validated();

        /** @var User|null $user */
        $user = User::query()->where('email', $credentials['email'])->first();
        if ($user === null || !Hash::check($credentials['password'], $user->password) || !$user->isActive) {
            throw new InvalidCredentialsException();
        }

        return $this->tokenResponse($user, $request);
    }

    /**
     * POST /api/v1/auth/register
     *
     * Public self-registration. The account always starts as PENDING (set
     * here, never trusted from the request) so an admin must confirm it
     * before it gains member rights. We auto-login the new account so the
     * SPA can show the "awaiting approval" dashboard immediately.
     * Duplicate emails are rejected by RegisterRequest's unique rule.
     */
    public function register(RegisterRequest $request, UsersService $users, AdminNotifier $notifier): JsonResponse
    {
        $data = $request->validated();
        $user = $users->create([
            'name' => $data['name'],
            'email' => $data['email'],
            'password' => $data['password'],
            'role' => UserRole::PENDING,
            'isActive' => true,
            // GDPR: checkbox 1 is required (always true here); checkbox 2 is
            // optional and default-checked, so treat a missing value as true.
            'privacyAck' => true,
            'dataConsent' => (bool) ($data['dataConsent'] ?? true),
        ]);

        // Let an admin know someone is waiting for approval.
        $notifier->pendingMemberAwaitingApproval($user);

        return $this->tokenResponse($user, $request, 201);
    }

    /**
     * GET /api/v1/auth/me — return the currently-authenticated user.
     */
    public function me(Request $request): UserResource
    {
        /** @var User $user */
        $user = $request->user();

        return new UserResource($user);
    }

    /**
     * POST /api/v1/auth/logout — revoke the token used for this request.
     */
    public function logout(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $token = $user->currentAccessToken();
        if ($token && method_exists($token, 'delete')) {
            $token->delete();
        }

        return new JsonResponse(null, 204);
    }

    /**
     * GET /api/v1/auth/captcha — issue a stateless captcha challenge for
     * the forgot-password form. Returns an inline SVG plus an opaque token
     * that the client echoes back with the user's answer.
     */
    public function captcha(CaptchaService $captcha): JsonResponse
    {
        $challenge = $captcha->issue();

        return new JsonResponse([
            'token' => $challenge['token'],
            'svg' => $challenge['svg'],
        ]);
    }

    /**
     * POST /api/v1/auth/forgot-password — captcha-gated. Always returns a
     * generic 200 (even for unknown emails) so the endpoint can't be used
     * to enumerate accounts. If the email exists + is active, a reset link
     * is emailed.
     */
    public function forgotPassword(
        ForgotPasswordRequest $request,
        CaptchaService $captcha,
        PasswordResetService $passwordReset,
    ): JsonResponse {
        $data = $request->validated();

        if (!$captcha->verify($data['captchaAnswer'], $data['captchaToken'])) {
            throw ValidationException::withMessages([
                'captchaAnswer' => 'Nesprávne overenie. Skúste to znova.',
            ]);
        }

        $passwordReset->requestReset($data['email']);

        return new JsonResponse([
            'message' => 'Ak účet s týmto e-mailom existuje, poslali sme naň odkaz na obnovu hesla.',
        ]);
    }

    /**
     * POST /api/v1/auth/reset-password — consume a reset/invitation token
     * and set the new password. On success the user is logged in (a fresh
     * token is returned) so they don't have to type credentials again.
     */
    public function resetPassword(ResetPasswordRequest $request, PasswordResetService $passwordReset): JsonResponse
    {
        $data = $request->validated();
        $consents = [];
        if (array_key_exists('privacyAck', $data)) {
            $consents['privacyAck'] = (bool) $data['privacyAck'];
        }
        if (array_key_exists('dataConsent', $data)) {
            $consents['dataConsent'] = (bool) $data['dataConsent'];
        }
        $user = $passwordReset->reset($data['email'], $data['token'], $data['password'], $consents);

        return $this->tokenResponse($user, $request);
    }

    /**
     * GET /api/v1/auth/providers — which social logins are live. Empty
     * while OAuth is dormant (no client id/secret), so the SPA hides the
     * buttons. See docs/AUTH-AND-PERMISSIONS.md.
     */
    public function providers(): JsonResponse
    {
        $providers = [];
        foreach (OAuthProvider::cases() as $provider) {
            if ($provider->isConfigured()) {
                $providers[] = [
                    'provider' => $provider->value,
                    'label' => $provider->label(),
                    'url' => url('/api/v1/auth/oauth/'.$provider->value.'/redirect'),
                ];
            }
        }

        return new JsonResponse(['providers' => $providers]);
    }

    private function tokenResponse(User $user, Request $request, int $status = 200): JsonResponse
    {
        $tokenName = 'spa:'.($request->userAgent() ?: 'unknown');
        $token = $user->createToken($tokenName)->plainTextToken;

        return new JsonResponse([
            'token' => $token,
            'user' => (new UserResource($user))->toArray($request),
        ], $status);
    }
}
