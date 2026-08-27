<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\CreateDamageCommentRequest;
use App\Http\Resources\DamageCommentResource;
use App\Services\DamageCommentsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Comments on a damage. Confirmed-members-only end to end — reading
 * included — because every comment carries its author's name. The gate
 * lives on the route group; see routes/api.php.
 */
class DamageCommentsController extends Controller
{
    public function __construct(private readonly DamageCommentsService $comments) {}

    public function index(string $id): JsonResponse
    {
        return new JsonResponse([
            'items' => DamageCommentResource::collection(
                $this->comments->listFor($id),
            )->resolve(),
        ]);
    }

    public function store(CreateDamageCommentRequest $request, string $id): JsonResponse
    {
        $comment = $this->comments->create(
            $id,
            $request->user(),
            $request->validated('body'),
        );

        return (new DamageCommentResource($comment))
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }

    public function destroy(Request $request, string $id, string $commentId): JsonResponse
    {
        $this->comments->delete($id, $commentId, $request->user());

        return new JsonResponse(null, Response::HTTP_NO_CONTENT);
    }
}
