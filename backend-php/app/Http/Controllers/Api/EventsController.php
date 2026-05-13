<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\AddParticipantRequest;
use App\Http\Requests\AttachResourcesRequest;
use App\Http\Requests\CreateEventRequest;
use App\Http\Requests\ListEventsRequest;
use App\Http\Requests\UpdateEventRequest;
use App\Http\Resources\EventParticipantResource;
use App\Http\Resources\EventResource;
use App\Http\Resources\ReservationResource;
use App\Http\Support\Paginated;
use App\Services\EventsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Symfony\Component\HttpFoundation\Response;

class EventsController extends Controller
{
    public function __construct(private readonly EventsService $events) {}

    public function store(CreateEventRequest $request): JsonResponse
    {
        $event = $this->events->create($request->validated());

        return (new EventResource($event))
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }

    public function index(ListEventsRequest $request): array
    {
        $page = (int) ($request->validated('page') ?? 1);
        $pageSize = (int) ($request->validated('pageSize') ?? 25);

        $result = $this->events->list([
            'from' => $request->validated('from'),
            'to' => $request->validated('to'),
            'skip' => ($page - 1) * $pageSize,
            'take' => $pageSize,
        ]);

        return Paginated::from(
            $result['items'],
            $result['total'],
            $page,
            $pageSize,
            EventResource::class,
        );
    }

    public function show(string $id): EventResource
    {
        return new EventResource($this->events->findById($id));
    }

    public function update(UpdateEventRequest $request, string $id): EventResource
    {
        return new EventResource($this->events->update($id, $request->validated()));
    }

    public function destroy(string $id): JsonResponse
    {
        $this->events->remove($id);

        return new JsonResponse(null, Response::HTTP_NO_CONTENT);
    }

    public function listParticipants(string $id): AnonymousResourceCollection
    {
        return EventParticipantResource::collection(
            $this->events->listParticipants($id),
        );
    }

    public function addParticipant(AddParticipantRequest $request, string $id): JsonResponse
    {
        $participant = $this->events->addParticipant($id, $request->validated());

        return (new EventParticipantResource($participant))
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }

    public function removeParticipant(string $id, string $participantId): JsonResponse
    {
        $this->events->removeParticipant($id, $participantId);

        return new JsonResponse(null, Response::HTTP_NO_CONTENT);
    }

    public function attachResources(AttachResourcesRequest $request, string $id): JsonResponse
    {
        $reservations = $this->events->attachResources($id, $request->validated('resourceIds'));

        return ReservationResource::collection($reservations)
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }
}
