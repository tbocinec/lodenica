<?php

namespace App\Http\Controllers\Api;

use App\Domain\Enums\OAuthProvider;
use App\Http\Controllers\Controller;
use App\Http\Requests\ChangePasswordRequest;
use App\Http\Resources\UserIdentityResource;
use App\Models\User;
use App\Services\OAuthService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

/**
 * The signed-in user's own account screen. Any authenticated user
 * (including PENDING) may change their own password and manage their linked
 * social logins. Changing OTHER people's passwords is admin-only and lives
 * in UsersController. See docs/AUTH-AND-PERMISSIONS.md.
 */
class ProfileController extends Controller
{
    /**
     * POST /api/v1/profile/change-password — change own password after
     * confirming the current one. The current request's token stays valid;
     * we don't force a re-login of the active session.
     */
    public function changePassword(ChangePasswordRequest $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $data = $request->validated();

        if (!Hash::check($data['currentPassword'], $user->password)) {
            throw ValidationException::withMessages([
                'currentPassword' => 'Súčasné heslo nie je správne.',
            ]);
        }

        $user->password = $data['newPassword']; // hashed via cast
        $user->save();

        // Revoke every OTHER token so stale sessions elsewhere are killed,
        // but keep the one making this request alive.
        $current = $user->currentAccessToken();
        $currentId = $current && isset($current->id) ? $current->id : null;
        $user->tokens()->when($currentId, fn ($q) => $q->where('id', '!=', $currentId))->delete();

        return new JsonResponse(['message' => 'Heslo bolo zmenené.']);
    }

    /**
     * GET /api/v1/profile/identities — list this user's linked social logins.
     */
    public function identities(Request $request): AnonymousResourceCollection
    {
        /** @var User $user */
        $user = $request->user();

        return UserIdentityResource::collection($user->identities()->get());
    }

    /**
     * DELETE /api/v1/profile/identities/{provider} — unlink a social login.
     */
    public function unlinkIdentity(Request $request, string $provider, OAuthService $oauth): JsonResponse
    {
        $resolved = OAuthProvider::tryFrom($provider);
        if ($resolved === null) {
            return new JsonResponse(null, Response::HTTP_NOT_FOUND);
        }

        /** @var User $user */
        $user = $request->user();
        $oauth->unlink($user, $resolved);

        return new JsonResponse(null, Response::HTTP_NO_CONTENT);
    }
}
