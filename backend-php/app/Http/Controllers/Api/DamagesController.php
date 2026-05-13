<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\CreateDamageRequest;
use App\Http\Requests\ListDamagesRequest;
use App\Http\Requests\UpdateDamageRequest;
use App\Http\Resources\DamageResource;
use App\Http\Support\Paginated;
use App\Services\DamagesService;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

class DamagesController extends Controller
{
    public function __construct(private readonly DamagesService $damages) {}

    public function store(CreateDamageRequest $request): JsonResponse
    {
        $damage = $this->damages->create($request->validated());

        return (new DamageResource($damage))
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }

    public function index(ListDamagesRequest $request): array
    {
        $page = (int) ($request->validated('page') ?? 1);
        $pageSize = (int) ($request->validated('pageSize') ?? 25);

        $result = $this->damages->list([
            'resourceId' => $request->validated('resourceId'),
            'status' => $request->validated('status'),
            'skip' => ($page - 1) * $pageSize,
            'take' => $pageSize,
        ]);

        return Paginated::from(
            $result['items'],
            $result['total'],
            $page,
            $pageSize,
            DamageResource::class,
        );
    }

    public function show(string $id): DamageResource
    {
        return new DamageResource($this->damages->findById($id));
    }

    public function update(UpdateDamageRequest $request, string $id): DamageResource
    {
        return new DamageResource($this->damages->update($id, $request->validated()));
    }

    public function destroy(string $id): JsonResponse
    {
        $this->damages->remove($id);

        return new JsonResponse(null, Response::HTTP_NO_CONTENT);
    }
}
