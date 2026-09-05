<?php

namespace App\Services;

use App\Domain\Enums\ReservationStatus;
use App\Models\Reservation;

/**
 * Calendar representations of a reservation: the single-event `.ics` file
 * (download endpoint and e-mail attachment) and a Google Calendar "render
 * template" link that pre-fills a new event.
 *
 * Wall-clock UTC convention (CORE-001): what the user typed is what we
 * send, stamped `Z`. Summary, location and the maps line follow the site
 * configuration; REZ-063 marks a waiting request TENTATIVE.
 */
final class ReservationCalendar
{
    public function __construct(private readonly SiteConfig $site) {}

    public function ics(Reservation $reservation): string
    {
        $fmt = fn (\DateTimeInterface $d) => $d->format('Ymd\THis\Z');
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $host = parse_url((string) config('app.url'), PHP_URL_HOST) ?: 'localhost';
        $address = (string) $this->site->get('address');

        $lines = [
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'PRODID:-//'.self::escape($this->site->siteName()).'//SK',
            'CALSCALE:GREGORIAN',
            'METHOD:PUBLISH',
            'BEGIN:VEVENT',
            'UID:'.$reservation->id.'@'.$host,
            'DTSTAMP:'.$fmt($now),
            'DTSTART:'.$fmt(self::instant($reservation->startsAt)),
            'DTEND:'.$fmt(self::instant($reservation->endsAt)),
            'SUMMARY:'.self::escape($this->summary($reservation)),
            'DESCRIPTION:'.self::escape(implode("\n", $this->descriptionLines($reservation))),
        ];
        if ($address !== '') {
            $lines[] = 'LOCATION:'.self::escape($address);
        }
        // REZ-063: a waiting request is tentative in the user's calendar.
        $lines[] = 'STATUS:'.match ($reservation->status) {
            ReservationStatus::CONFIRMED => 'CONFIRMED',
            ReservationStatus::PENDING_APPROVAL => 'TENTATIVE',
            default => 'CANCELLED',
        };
        $lines[] = 'END:VEVENT';
        $lines[] = 'END:VCALENDAR';

        return implode("\r\n", $lines)."\r\n";
    }

    public function icsFilename(Reservation $reservation): string
    {
        return 'rezervacia-'.substr($reservation->id, 0, 8).'.ics';
    }

    /** Absolute URL of the public download endpoint. */
    public function icsUrl(Reservation $reservation): string
    {
        return rtrim((string) config('app.url'), '/').'/api/v1/reservations/'.$reservation->id.'/ics';
    }

    /**
     * Google Calendar template link: opens the "new event" editor pre-filled
     * (web on desktop, the Calendar app on Android). Same fields as the
     * SPA's `reservationGoogleCalendarUrl`, built server-side for e-mail.
     */
    public function googleCalendarUrl(Reservation $reservation): string
    {
        $compact = fn (\DateTimeInterface $d) => $d->format('Ymd\THis\Z');
        $params = [
            'action' => 'TEMPLATE',
            'text' => $this->summary($reservation),
            'dates' => $compact(self::instant($reservation->startsAt)).'/'.$compact(self::instant($reservation->endsAt)),
            'details' => implode("\n", $this->descriptionLines($reservation)),
        ];
        $address = (string) $this->site->get('address');
        if ($address !== '') {
            $params['location'] = $address;
        }

        return 'https://www.google.com/calendar/render?'.http_build_query($params, '', '&', PHP_QUERY_RFC3986);
    }

    private function summary(Reservation $reservation): string
    {
        $resource = $reservation->resource;
        $siteName = $this->site->siteName();

        return $resource
            ? "{$siteName}: {$resource->identifier} – {$resource->name}"
            : "{$siteName}: rezervácia";
    }

    /**
     * The club's maps link (when configured) goes first on its own line —
     * most calendar apps render plain URLs as tappable links.
     *
     * @return list<string>
     */
    private function descriptionLines(Reservation $reservation): array
    {
        $resource = $reservation->resource;

        return array_values(array_filter([
            $this->site->get('mapsUrl'),
            'Rezervácia pre: '.$reservation->customerName,
            $reservation->customerContact ? 'Kontakt: '.$reservation->customerContact : null,
            $resource ? 'Zdroj: '.$resource->identifier.' '.$resource->name : null,
            $reservation->note ? 'Poznámka: '.$reservation->note : null,
        ]));
    }

    private static function instant(mixed $value): \DateTimeInterface
    {
        return $value instanceof \DateTimeInterface
            ? $value
            : new \DateTimeImmutable((string) $value);
    }

    /** RFC 5545 §3.3.11 escape: backslash, comma, semicolon, newline. */
    private static function escape(string $value): string
    {
        return strtr($value, [
            '\\' => '\\\\',
            ',' => '\\,',
            ';' => '\\;',
            "\n" => '\\n',
        ]);
    }
}
