<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\CreateResourceRequest;
use App\Http\Requests\ListResourcesRequest;
use App\Http\Requests\UpdateResourceRequest;
use App\Http\Resources\ResourceResource;
use App\Http\Support\Paginated;
use App\Services\ResourcesService;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

class ResourcesController extends Controller
{
    public function __construct(private readonly ResourcesService $resources) {}

    public function store(CreateResourceRequest $request): JsonResponse
    {
        $resource = $this->resources->create($request->validated());

        return (new ResourceResource($resource))
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }

    public function index(ListResourcesRequest $request): array
    {
        $page = (int) ($request->validated('page') ?? 1);
        $pageSize = (int) ($request->validated('pageSize') ?? 25);

        $result = $this->resources->list([
            'type' => $request->validated('type'),
            'isActive' => $request->validated('isActive'),
            'search' => $request->validated('search'),
            'skip' => ($page - 1) * $pageSize,
            'take' => $pageSize,
        ]);

        return Paginated::from(
            $result['items'],
            $result['total'],
            $page,
            $pageSize,
            ResourceResource::class,
        );
    }

    public function show(string $id): ResourceResource
    {
        return new ResourceResource($this->resources->findById($id));
    }

    public function update(UpdateResourceRequest $request, string $id): ResourceResource
    {
        return new ResourceResource(
            $this->resources->update($id, $request->validated()),
        );
    }

    public function destroy(string $id): JsonResponse
    {
        $this->resources->delete($id);

        return new JsonResponse(null, Response::HTTP_NO_CONTENT);
    }

    public function deactivate(string $id): ResourceResource
    {
        return new ResourceResource($this->resources->setActive($id, false));
    }

    public function activate(string $id): ResourceResource
    {
        return new ResourceResource($this->resources->setActive($id, true));
    }
}
