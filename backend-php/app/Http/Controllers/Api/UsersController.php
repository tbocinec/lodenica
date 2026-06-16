<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\CreateUserRequest;
use App\Http\Requests\UpdateUserRequest;
use App\Http\Resources\UserResource;
use App\Http\Support\Paginated;
use App\Models\User;
use App\Services\UsersService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
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
        /** @var User $actor */
        $actor = $request->user();
        $user = $this->users->confirmPending($id, $actor);

        return new UserResource($user);
    }
}
