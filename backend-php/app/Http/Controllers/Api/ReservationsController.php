<?php

namespace App\Http\Controllers\Api;

use App\Domain\ValueObjects\TimeRange;
use App\Http\Controllers\Controller;
use App\Http\Requests\CreateReservationRequest;
use App\Http\Requests\ListReservationsRequest;
use App\Http\Requests\UpdateReservationRequest;
use App\Http\Resources\ReservationResource;
use App\Http\Support\Paginated;
use App\Services\ReservationsService;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

class ReservationsController extends Controller
{
    public function __construct(private readonly ReservationsService $reservations) {}

    public function store(CreateReservationRequest $request): JsonResponse
    {
        $reservation = $this->reservations->create($request->validated());

        return (new ReservationResource($reservation))
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }

    public function index(ListReservationsRequest $request): array
    {
        $page = (int) ($request->validated('page') ?? 1);
        $pageSize = (int) ($request->validated('pageSize') ?? 25);

        $range = null;
        $from = $request->validated('from');
        $to = $request->validated('to');
        if ($from && $to) {
            $range = TimeRange::fromInstants($from, $to);
        }

        $result = $this->reservations->list([
            'resourceId' => $request->validated('resourceId'),
            'eventId' => $request->validated('eventId'),
            'status' => $request->validated('status'),
            'range' => $range,
            'skip' => ($page - 1) * $pageSize,
            'take' => $pageSize,
        ]);

        return Paginated::from(
            $result['items'],
            $result['total'],
            $page,
            $pageSize,
            ReservationResource::class,
        );
    }

    public function show(string $id): ReservationResource
    {
        return new ReservationResource($this->reservations->findById($id));
    }

    public function update(UpdateReservationRequest $request, string $id): ReservationResource
    {
        return new ReservationResource(
            $this->reservations->update($id, $request->validated()),
        );
    }

    public function cancel(string $id): ReservationResource
    {
        return new ReservationResource($this->reservations->cancel($id));
    }

    public function destroy(string $id): JsonResponse
    {
        $this->reservations->remove($id);

        return new JsonResponse(null, Response::HTTP_NO_CONTENT);
    }
}
