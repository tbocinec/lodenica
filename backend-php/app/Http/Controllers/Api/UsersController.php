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
        return new UserResource($this->users->findById($id));
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
            // Admin assigns the internal member ID when confirming. Optional
            // (can be set later via update); unique across users.
            'memberId' => ['nullable', 'string', 'max:100'],
        ]);
        $memberId = isset($validated['memberId']) && trim((string) $validated['memberId']) !== ''
            ? trim((string) $validated['memberId'])
            : null;

        /** @var User $actor */
        $actor = $request->user();
        $user = $this->users->confirmPending($id, $actor, $memberId);

        return new UserResource($user);
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
            'isActive' => true,
            'memberId' => $data['memberId'] ?? null,
        ]);

        $passwordReset->sendInvitation($user);

        return (new UserResource($user))
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }
}
