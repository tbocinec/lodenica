<?php

namespace App\Http\Controllers\Api;

use App\Domain\Enums\AuditEntityType;
use App\Domain\Enums\MailNotification;
use App\Domain\Enums\OAuthProvider;
use App\Http\Controllers\Controller;
use App\Http\Requests\ChangePasswordRequest;
use App\Http\Resources\UserIdentityResource;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\OAuthService;
use App\Services\UserNotificationPreferences;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
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

    /**
     * GET /api/v1/profile/notifications — the user's own e-mail switches
     * (REZ-062). Only user-configurable notifications are listed; the label
     * and description come from the enum so the profile never drifts from
     * the admin diagnostics page.
     */
    public function notifications(Request $request, UserNotificationPreferences $prefs): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        return new JsonResponse(['notifications' => $this->presentPreferences($prefs->all($user))]);
    }

    /** PATCH /api/v1/profile/notifications — partial update, `{ key: bool }`. */
    public function updateNotifications(Request $request, UserNotificationPreferences $prefs): JsonResponse
    {
        $known = array_map(fn (MailNotification $t) => $t->value, UserNotificationPreferences::configurable());

        $changes = $request->isJson() ? $request->json()->all() : $request->post();

        $unknown = array_diff(array_keys($changes), $known);
        if ($unknown !== []) {
            throw ValidationException::withMessages([
                'notifications' => 'Túto notifikáciu si nemôžeš nastaviť: '.implode(', ', $unknown),
            ]);
        }
        Validator::make($changes, array_fill_keys($known, ['sometimes', 'boolean']))->validate();

        /** @var User $user */
        $user = $request->user();
        $state = $prefs->update($user, $changes);

        return new JsonResponse(['notifications' => $this->presentPreferences($state)]);
    }

    /**
     * PATCH /api/v1/profile/appearance — `{ theme: slug|null }`. Null means
     * "use the site default" (THEME-001). The slug is only shape-checked
     * here; the SPA owns the list of themes and falls back to the default
     * for a key it does not know.
     */
    public function updateAppearance(Request $request, AuditLogger $audit): UserResource
    {
        $data = $request->validate([
            'theme' => ['present', 'nullable', 'string', 'regex:/^[a-z][a-z0-9-]{1,31}$/'],
        ]);

        /** @var User $user */
        $user = $request->user();
        $before = $user->theme;
        if ($before !== $data['theme']) {
            $user->theme = $data['theme'];
            $user->save();
            $audit->logUpdate(
                AuditEntityType::USER,
                $user,
                "Zmenená téma vzhľadu používateľa „{$user->name}“",
                ['theme' => $before],
                ['theme' => $data['theme']],
            );
        }

        return new UserResource($user);
    }

    /**
     * @param  array<string, bool>  $state
     * @return list<array<string, mixed>>
     */
    private function presentPreferences(array $state): array
    {
        return array_map(fn (MailNotification $type) => [
            'key' => $type->value,
            'label' => $type->label(),
            'description' => $type->description(),
            'enabled' => $state[$type->value],
        ], UserNotificationPreferences::configurable());
    }
}
