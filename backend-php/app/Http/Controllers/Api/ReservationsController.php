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
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ReservationsController extends Controller
{
    public function __construct(private readonly ReservationsService $reservations) {}

    public function store(CreateReservationRequest $request): JsonResponse
    {
        $cmd = $request->validated();
        // Stamp the booking with its creator AND their internal member ID
        // when made by a logged-in user (public route → resolve the sanctum
        // guard explicitly). Anonymous bookings stay ownerless. The memberId
        // snapshot lets "my reservations" follow the member identity. See
        // docs/AUTH-AND-PERMISSIONS.md.
        $user = $request->user('sanctum');
        if ($user !== null) {
            $cmd['createdById'] = $user->id;
            $cmd['memberId'] = $user->memberId;
        }

        $reservation = $this->reservations->create($cmd);

        return (new ReservationResource($reservation))
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }

    /**
     * GET /api/v1/reservations/mine — the logged-in user's own bookings
     * (everything they created), newest first. Any authenticated account,
     * including PENDING. See docs/AUTH-AND-PERMISSIONS.md.
     */
    public function mine(Request $request): array
    {
        $page = (int) ($request->query('page') ?? 1);
        $pageSize = (int) ($request->query('pageSize') ?? 50);

        $user = $request->user();
        $result = $this->reservations->list([
            'mineUserId' => $user->id,
            'mineMemberId' => $user->memberId,
            'skip' => ($page - 1) * $pageSize,
            'take' => $pageSize,
            'orderByLatest' => true,
        ]);

        return Paginated::from(
            $result['items'],
            $result['total'],
            $page,
            $pageSize,
            ReservationResource::class,
        );
    }

    public function index(ListReservationsRequest $request): array
    {
        $page = (int) ($request->validated('page') ?? 1);
        $pageSize = (int) ($request->validated('pageSize') ?? 25);

        // Independent from / to so the frontend can ask "from now onward"
        // without inventing a far-future upper bound. When both are set
        // we use the overlap semantics via TimeRange; with only one we
        // pass it through as a direct lower / upper bound on endsAt /
        // startsAt respectively.
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
            'startsAtFrom' => $range ? null : $from,
            'endsAtTo' => $range ? null : $to,
            'search' => $request->validated('search'),
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
        $data = $request->validated();
        // Reassigning ownership (memberId) is admin-only — strip it otherwise.
        $user = $request->user('sanctum') ?? $request->user();
        if (array_key_exists('memberId', $data) && !($user && $user->isAdmin())) {
            unset($data['memberId']);
        }

        return new ReservationResource(
            $this->reservations->update($id, $data),
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
            ? "Lodenica KVŠ: {$resource->identifier} – {$resource->name}"
            : 'Lodenica KVŠ: rezervácia';

        // The Google Maps short link is intentionally on its own line at
        // the top of the description — most calendar apps render plain
        // URLs as tappable links, so the user can navigate from the
        // event view straight to the boathouse.
        $description = trim(implode("\\n", array_filter([
            'https://maps.app.goo.gl/zZwKA168QCeugSxA8',
            'Rezervácia pre: '.$reservation->customerName,
            $reservation->customerContact ? 'Kontakt: '.$reservation->customerContact : null,
            $resource ? 'Zdroj: '.$resource->identifier.' '.$resource->name : null,
            $reservation->note ? 'Poznámka: '.$reservation->note : null,
        ])));

        $lines = [
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'PRODID:-//Lodenica KVŠ//SK',
            'CALSCALE:GREGORIAN',
            'METHOD:PUBLISH',
            'BEGIN:VEVENT',
            'UID:'.$reservation->id.'@rezervacie.lodenicakvs.sk',
            'DTSTAMP:'.$fmt($now),
            'DTSTART:'.$fmt($start),
            'DTEND:'.$fmt($end),
            'SUMMARY:'.$this->icalEscape($summary),
            'DESCRIPTION:'.$this->icalEscape($description),
            'LOCATION:'.$this->icalEscape('Klub vodných športov Karlova Ves, Botanická 20/59, 841 04 Bratislava-Karlova Ves, Slovakia'),
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
