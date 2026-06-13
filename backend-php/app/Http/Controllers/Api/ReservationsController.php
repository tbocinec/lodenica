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

    /**
     * GET /api/v1/reservations/{id}/ics
     *
     * Single-event iCalendar file that mobile browsers know how to hand
     * off to the user's calendar app (iOS Calendar / Google Calendar /
     * Outlook). Used by the "Pridať do kalendára" button shown right
     * after a reservation is created. Public — no auth check; the
     * reservation id is already a UUID so guessing is infeasible.
     */
    public function ics(string $id): \Symfony\Component\HttpFoundation\Response
    {
        $reservation = $this->reservations->findById($id);
        $resource = $reservation->resource()->first();

        // Wall-clock UTC convention: what the user typed is what we send.
        $fmt = fn (\DateTimeInterface $d) => $d->format('Ymd\THis\Z');
        $start = $reservation->startsAt instanceof \DateTimeInterface
            ? $reservation->startsAt
            : new \DateTimeImmutable((string) $reservation->startsAt);
        $end = $reservation->endsAt instanceof \DateTimeInterface
            ? $reservation->endsAt
            : new \DateTimeImmutable((string) $reservation->endsAt);
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));

        $summary = $resource
            ? "Lodenica KVS: {$resource->identifier} – {$resource->name}"
            : 'Lodenica KVS: rezervácia';
        $description = trim(implode("\\n", array_filter([
            'Zákazník: '.$reservation->customerName,
            $reservation->customerContact ? 'Kontakt: '.$reservation->customerContact : null,
            $resource ? 'Zdroj: '.$resource->identifier.' '.$resource->name : null,
            $reservation->note ? 'Poznámka: '.$reservation->note : null,
        ])));

        $lines = [
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'PRODID:-//Lodenica KVS//SK',
            'CALSCALE:GREGORIAN',
            'METHOD:PUBLISH',
            'BEGIN:VEVENT',
            'UID:'.$reservation->id.'@rezervacie.lodenicakvs.sk',
            'DTSTAMP:'.$fmt($now),
            'DTSTART:'.$fmt($start),
            'DTEND:'.$fmt($end),
            'SUMMARY:'.$this->icalEscape($summary),
            'DESCRIPTION:'.$this->icalEscape($description),
            'LOCATION:Lodenica KVS',
            'STATUS:'.($reservation->status->value === 'CONFIRMED' ? 'CONFIRMED' : 'CANCELLED'),
            'END:VEVENT',
            'END:VCALENDAR',
        ];

        $body = implode("\r\n", $lines)."\r\n";

        return response($body, 200, [
            'Content-Type' => 'text/calendar; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="rezervacia-'.substr($reservation->id, 0, 8).'.ics"',
            'X-Robots-Tag' => 'noindex, nofollow',
        ]);
    }

    /** RFC 5545 §3.3.11 escape: backslash, comma, semicolon, newline. */
    private function icalEscape(string $value): string
    {
        return strtr($value, [
            '\\' => '\\\\',
            ',' => '\\,',
            ';' => '\\;',
            "\n" => '\\n',
        ]);
    }
}
