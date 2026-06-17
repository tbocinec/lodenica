<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\NotFoundDomainException;
use App\Http\Controllers\Controller;
use App\Http\Requests\CreateResourceRequest;
use App\Http\Requests\ListResourcesRequest;
use App\Http\Requests\UpdateResourceRequest;
use App\Http\Resources\ResourceResource;
use App\Http\Support\Paginated;
use App\Services\ResourcesService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
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

    /**
     * POST /api/v1/resources/{id}/photo — attach/replace the resource's
     * photo (multipart `photo` field). Admin-only (route group). Mirrors
     * the damage photo: image mimes only, 5 MB cap, deterministic filename
     * so re-uploads overwrite rather than accumulate orphans.
     */
    public function addPhoto(Request $request, string $id): ResourceResource
    {
        $request->validate([
            'photo' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
        ]);
        $resource = $this->resources->findById($id);

        $file = $request->file('photo');
        $ext = strtolower($file->extension() ?: $file->getClientOriginalExtension() ?: 'jpg');
        $path = "resources/{$resource->id}.{$ext}";

        if ($resource->photoPath && $resource->photoPath !== $path) {
            Storage::disk('local')->delete($resource->photoPath);
        }

        Storage::disk('local')->putFileAs('resources', $file, "{$resource->id}.{$ext}");
        $resource->photoPath = $path;
        $resource->save();

        return new ResourceResource($resource->refresh());
    }

    /**
     * GET /api/v1/resources/{id}/photo — stream the photo from the
     * protected storage dir. Public (so the boat detail shows it to anyone).
     */
    public function showPhoto(string $id): BinaryFileResponse
    {
        $resource = $this->resources->findById($id);
        if (!$resource->photoPath) {
            throw new NotFoundDomainException('ResourcePhoto', $id);
        }
        $absolute = Storage::disk('local')->path($resource->photoPath);
        if (!is_file($absolute)) {
            throw new NotFoundDomainException('ResourcePhoto', $id);
        }

        return response()->file($absolute, [
            'Cache-Control' => 'private, max-age=3600',
            'X-Robots-Tag' => 'noindex, nofollow',
        ]);
    }

    /** DELETE /api/v1/resources/{id}/photo — admin-only. */
    public function removePhoto(string $id): JsonResponse
    {
        $resource = $this->resources->findById($id);
        if ($resource->photoPath) {
            Storage::disk('local')->delete($resource->photoPath);
            $resource->photoPath = null;
            $resource->save();
        }

        return new JsonResponse(null, Response::HTTP_NO_CONTENT);
    }
}
