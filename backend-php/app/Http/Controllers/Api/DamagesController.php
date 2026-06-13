<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\NotFoundDomainException;
use App\Http\Controllers\Controller;
use App\Http\Requests\CreateDamageRequest;
use App\Http\Requests\ListDamagesRequest;
use App\Http\Requests\UpdateDamageRequest;
use App\Http\Resources\DamageResource;
use App\Http\Support\Paginated;
use App\Services\DamagesService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
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

    /**
     * POST /api/v1/damages/{id}/photo
     *
     * Optional photo attached to a damage report. Multipart form-data
     * with `photo` file field. Capped at 5 MB and image mimes only —
     * the club doesn't need a full media library, just enough proof so
     * an admin can verify "crack on the hull" looks credible.
     *
     * Re-uploads overwrite the previous file (deterministic filename
     * = damageId + extension) so we never accumulate orphans.
     */
    public function addPhoto(Request $request, string $id): DamageResource
    {
        $request->validate([
            'photo' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
        ]);
        $damage = $this->damages->findById($id);

        $file = $request->file('photo');
        $ext = strtolower($file->extension() ?: $file->getClientOriginalExtension() ?: 'jpg');
        $path = "damages/{$damage->id}.{$ext}";

        // If a previous photo used a different extension, remove it first
        // so we don't leave stale files behind.
        if ($damage->photoPath && $damage->photoPath !== $path) {
            Storage::disk('local')->delete($damage->photoPath);
        }

        Storage::disk('local')->putFileAs('damages', $file, "{$damage->id}.{$ext}");
        $damage->photoPath = $path;
        $damage->save();

        return new DamageResource($damage->refresh());
    }

    /**
     * GET /api/v1/damages/{id}/photo
     *
     * Streams the photo from the protected `storage/app/damages/` dir.
     * On Websupport with the all-in-docroot layout, the `/laravel/`
     * directory is denied at the .htaccess level — we serve via Laravel
     * so the file isn't directly web-accessible.
     */
    public function showPhoto(string $id): BinaryFileResponse
    {
        $damage = $this->damages->findById($id);
        if (!$damage->photoPath) {
            throw new NotFoundDomainException('DamagePhoto', $id);
        }
        $absolute = Storage::disk('local')->path($damage->photoPath);
        if (!is_file($absolute)) {
            throw new NotFoundDomainException('DamagePhoto', $id);
        }

        return response()
            ->file($absolute, [
                'Cache-Control' => 'private, max-age=3600',
                'X-Robots-Tag' => 'noindex, nofollow',
            ]);
    }

    public function removePhoto(string $id): JsonResponse
    {
        $damage = $this->damages->findById($id);
        if ($damage->photoPath) {
            Storage::disk('local')->delete($damage->photoPath);
            $damage->photoPath = null;
            $damage->save();
        }

        return new JsonResponse(null, Response::HTTP_NO_CONTENT);
    }
}
