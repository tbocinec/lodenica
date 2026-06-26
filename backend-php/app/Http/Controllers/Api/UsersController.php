<?php

namespace App\Http\Controllers\Api;

use App\Domain\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\BulkImportUsersRequest;
use App\Http\Requests\CreateUserRequest;
use App\Http\Requests\InviteUserRequest;
use App\Http\Requests\UpdateUserRequest;
use App\Http\Resources\UserResource;
use App\Http\Support\Paginated;
use App\Models\User;
use App\Services\BulkUserImportService;
use App\Services\PasswordResetService;
use App\Services\UsersService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class UsersController extends Controller
{
    public function __construct(private readonly UsersService $users) {}

    public function index(Request $request): array
    {
        $page = (int) $request->query('page', '1');
        $pageSize = (int) $request->query('pageSize', '50');

        $result = $this->users->list([
            'role' => $request->query('role'),
            'isActive' => $request->query('isActive'),
            'skip' => ($page - 1) * $pageSize,
            'take' => $pageSize,
        ]);

        return Paginated::from(
            $result['items'],
            $result['total'],
            $page,
            $pageSize,
            UserResource::class,
        );
    }

    public function store(CreateUserRequest $request): JsonResponse
    {
        $user = $this->users->create($request->validated());

        return (new UserResource($user))
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }

    public function show(string $id): UserResource
    {
        // Eager-load social identities so the admin user detail can list them.
        return new UserResource($this->users->findById($id)->load('identities'));
    }

    public function update(UpdateUserRequest $request, string $id): UserResource
    {
        /** @var User $actor */
        $actor = $request->user();

        return new UserResource($this->users->update($id, $request->validated(), $actor));
    }

    public function destroy(Request $request, string $id): JsonResponse
    {
        /** @var User $actor */
        $actor = $request->user();
        $this->users->delete($id, $actor);

        return new JsonResponse(null, Response::HTTP_NO_CONTENT);
    }

    /**
     * POST /api/v1/users/{id}/confirm
     *
     * Promote a PENDING account to MEMBER. Admin-only (route is in the
     * admin group). No-op when the target is already a member; rejects
     * with 422 if the target is an admin (admins are never demoted via
     * this endpoint — use full update for that).
     */
    public function confirm(Request $request, string $id): UserResource
    {
        $validated = $request->validate([
            // The internal member ID is MANDATORY at approval time and must
            // be unique across users.
            'memberId' => ['required', 'string', 'max:100', \Illuminate\Validation\Rule::unique('users', 'memberId')->ignore($id)],
        ], [
            'memberId.required' => 'Pri schválení člena musíte priradiť interné členské ID.',
            'memberId.unique' => 'Toto členské ID už má priradené iný používateľ.',
        ]);

        /** @var User $actor */
        $actor = $request->user();
        $user = $this->users->confirmPending($id, $actor, trim((string) $validated['memberId']));

        return new UserResource($user);
    }

    /**
     * DELETE /api/v1/users/{id}/identities/{provider}
     *
     * Admin: unlink a social login (Google/Facebook) from a member's account.
     * Idempotent — removing an absent identity is a no-op. Admin-only (route
     * is in the admin group).
     */
    public function unlinkIdentity(string $id, string $provider, \App\Services\OAuthService $oauth): JsonResponse
    {
        $user = $this->users->findById($id);
        $resolved = \App\Domain\Enums\OAuthProvider::tryFrom($provider);
        if ($resolved === null) {
            return new JsonResponse(null, Response::HTTP_NOT_FOUND);
        }
        $oauth->unlink($user, $resolved);

        return new JsonResponse(null, Response::HTTP_NO_CONTENT);
    }

    /**
     * POST /api/v1/users/import — bulk-create PENDING accounts from a CSV
     * of "name,email" rows. Each new account gets an invitation email with
     * a set-your-password link. Duplicates are skipped, malformed rows are
     * reported. Admin-only (route is in the admin group).
     */
    public function import(BulkImportUsersRequest $request, BulkUserImportService $importer): JsonResponse
    {
        $summary = $importer->import($request->validated('csv'));

        return new JsonResponse([
            'createdCount' => count($summary['created']),
            'skippedCount' => count($summary['skipped']),
            'invalidCount' => count($summary['invalid']),
            'created' => $summary['created'],
            'skipped' => $summary['skipped'],
            'invalid' => $summary['invalid'],
        ], Response::HTTP_CREATED);
    }

    /**
     * POST /api/v1/users/invite — invite a single member by name + email.
     * Same as a one-row CSV import: the account is created CONFIRMED
     * (MEMBER) and emailed a set-your-password link. Admin-only.
     */
    public function invite(InviteUserRequest $request, PasswordResetService $passwordReset): JsonResponse
    {
        $data = $request->validated();
        $user = $this->users->create([
            'name' => $data['name'],
            'email' => $data['email'],
            'password' => Str::random(40), // placeholder; set via invite link
            'role' => UserRole::MEMBER,     // admin-invited → auto-confirmed
            // Inactive until they set their own password via the invite link;
            // reset-password flips this on. So an un-activated invitee can't
            // log in yet (and the admin sees it as "pozvánka neprijatá").
            'isActive' => false,
            'memberId' => $data['memberId'] ?? null,
        ]);

        $passwordReset->sendInvitation($user);

        return (new UserResource($user))
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }
}
