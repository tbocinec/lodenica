<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\ForbiddenException;
use App\Exceptions\NotFoundDomainException;
use App\Http\Controllers\Controller;
use App\Http\Requests\CreateExpeditionRequest;
use App\Http\Requests\UpdateExpeditionRequest;
use App\Http\Resources\ExpeditionResource;
use App\Models\Expedition;
use App\Models\ExpeditionPhoto;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * Expedície — the members' world map of paddled places. Reads + creation are
 * member-gated (route group); editing/deleting an entry or its photos is
 * limited to the author or an admin. Photo files are streamed from protected
 * storage like damage/resource photos (showPhoto route is public so <img> tags
 * — which can't send the bearer token — can load them; the URLs carry
 * unguessable UUIDs).
 */
class ExpeditionsController extends Controller
{
    private const MAX_PHOTOS = 12;

    public function index(): AnonymousResourceCollection
    {
        $items = Expedition::query()
            ->with(['photos', 'creator'])
            ->orderByDesc('year')
            ->orderByDesc('createdAt')
            ->get();

        return ExpeditionResource::collection($items);
    }

    public function store(CreateExpeditionRequest $request): JsonResponse
    {
        $data = $request->validated();
        $data['createdById'] = $request->user()?->id;
        $data['publishConsent'] = true; // validated as accepted; normalise to bool

        $expedition = Expedition::create($data);
        $expedition->load(['photos', 'creator']);

        return (new ExpeditionResource($expedition))
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }

    public function update(UpdateExpeditionRequest $request, string $id): ExpeditionResource
    {
        $expedition = $this->requireExisting($id);
        $this->authorizeWrite($request, $expedition);

        $expedition->fill($request->validated());
        $expedition->save();
        $expedition->load(['photos', 'creator']);

        return new ExpeditionResource($expedition);
    }

    public function destroy(Request $request, string $id): JsonResponse
    {
        $expedition = $this->requireExisting($id);
        $this->authorizeWrite($request, $expedition);

        foreach ($expedition->photos as $photo) {
            Storage::disk('local')->delete($photo->path);
        }
        Storage::disk('local')->deleteDirectory("expeditions/{$expedition->id}");
        $expedition->delete(); // cascades the photo rows

        return new JsonResponse(null, Response::HTTP_NO_CONTENT);
    }

    public function addPhoto(Request $request, string $id): ExpeditionResource
    {
        $expedition = $this->requireExisting($id);
        $this->authorizeWrite($request, $expedition);
        $request->validate([
            'photo' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
        ]);

        if ($expedition->photos()->count() >= self::MAX_PHOTOS) {
            throw ValidationException::withMessages([
                'photo' => 'Expedícia môže mať najviac '.self::MAX_PHOTOS.' fotiek.',
            ]);
        }

        $file = $request->file('photo');
        $ext = strtolower($file->extension() ?: $file->getClientOriginalExtension() ?: 'jpg');

        // Create the row first so the file is named after its generated UUID.
        $photo = ExpeditionPhoto::create(['expeditionId' => $expedition->id, 'path' => 'pending']);
        $path = "expeditions/{$expedition->id}/{$photo->id}.{$ext}";
        Storage::disk('local')->putFileAs("expeditions/{$expedition->id}", $file, "{$photo->id}.{$ext}");
        $photo->path = $path;
        $photo->save();

        $expedition->load(['photos', 'creator']);

        return new ExpeditionResource($expedition);
    }

    public function showPhoto(string $id, string $photoId): BinaryFileResponse
    {
        $photo = ExpeditionPhoto::query()
            ->where('id', $photoId)
            ->where('expeditionId', $id)
            ->first();
        if ($photo === null) {
            throw new NotFoundDomainException('ExpeditionPhoto', $photoId);
        }
        $absolute = Storage::disk('local')->path($photo->path);
        if (!is_file($absolute)) {
            throw new NotFoundDomainException('ExpeditionPhoto', $photoId);
        }

        return response()->file($absolute, [
            'Cache-Control' => 'private, max-age=86400',
            'X-Robots-Tag' => 'noindex, nofollow',
        ]);
    }

    public function removePhoto(Request $request, string $id, string $photoId): JsonResponse
    {
        $expedition = $this->requireExisting($id);
        $this->authorizeWrite($request, $expedition);

        $photo = $expedition->photos()->where('id', $photoId)->first();
        if ($photo !== null) {
            Storage::disk('local')->delete($photo->path);
            $photo->delete();
        }

        return new JsonResponse(null, Response::HTTP_NO_CONTENT);
    }

    private function requireExisting(string $id): Expedition
    {
        $expedition = Expedition::find($id);
        if ($expedition === null) {
            throw new NotFoundDomainException('Expedition', $id);
        }

        return $expedition;
    }

    /** Only the author or an admin may modify an expedition. */
    private function authorizeWrite(Request $request, Expedition $expedition): void
    {
        $user = $request->user();
        if (!$user instanceof User) {
            throw new ForbiddenException('Neprihlásený používateľ.');
        }
        if ($user->isAdmin()) {
            return;
        }
        if ($expedition->createdById !== null && $expedition->createdById === $user->id) {
            return;
        }
        throw new ForbiddenException('Túto expedíciu môže upraviť iba jej autor alebo administrátor.');
    }
}
