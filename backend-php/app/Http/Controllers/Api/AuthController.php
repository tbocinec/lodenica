<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\InvalidCredentialsException;
use App\Http\Controllers\Controller;
use App\Http\Requests\LoginRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

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

        $tokenName = 'spa:'.($request->userAgent() ?: 'unknown');
        $token = $user->createToken($tokenName)->plainTextToken;

        return new JsonResponse([
            'token' => $token,
            'user' => (new UserResource($user))->toArray($request),
        ]);
    }

    /**
     * GET /api/v1/auth/me — return the currently-authenticated user.
     * Used by the SPA on boot to validate a stored token.
     */
    public function me(Request $request): UserResource
    {
        /** @var User $user */
        $user = $request->user();

        return new UserResource($user);
    }

    /**
     * POST /api/v1/auth/logout — revoke the token used for this request.
     * Other tokens (e.g. an old browser tab) are left alone.
     */
    public function logout(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $token = $user->currentAccessToken();
        // PersonalAccessToken implements delete(); the IDE stub on
        // TransientToken does not, so guard it.
        if ($token && method_exists($token, 'delete')) {
            $token->delete();
        }

        return new JsonResponse(null, 204);
    }
}
