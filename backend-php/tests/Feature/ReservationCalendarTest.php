<?php

namespace Tests\Feature;

use App\Domain\Enums\ResourceType;
use App\Models\Resource;
use App\Services\ReservationCalendar;
use App\Services\ReservationsService;
use App\Services\SiteConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReservationCalendarTest extends TestCase
{
    use RefreshDatabase;

    private function reservation(): \App\Models\Reservation
    {
        $resource = Resource::create(['identifier' => 'K-CAL', 'type' => ResourceType::SEA_KAYAK, 'name' => 'Cetus']);

        return app(ReservationsService::class)->create([
            'resourceId' => $resource->id,
            'customerName' => 'Janka',
            'startsAt' => '2099-08-12T09:00:00Z',
            'endsAt' => '2099-08-12T12:00:00Z',
            'note' => 'Devín, potom späť',
        ]);
    }

    public function test_google_calendar_link_prefills_the_event(): void
    {
        config(['app.url' => 'https://rez.example.test']);
        app(SiteConfig::class)->update(['siteName' => 'Klub Test', 'address' => 'Prístav 1', 'mapsUrl' => 'https://maps.example.test/x']);
        $reservation = $this->reservation();

        $url = app(ReservationCalendar::class)->googleCalendarUrl($reservation);
        parse_str((string) parse_url($url, PHP_URL_QUERY), $q);

        $this->assertStringStartsWith('https://www.google.com/calendar/render?', $url);
        $this->assertSame('TEMPLATE', $q['action']);
        $this->assertSame('Klub Test: K-CAL – Cetus', $q['text']);
        $this->assertSame('20990812T090000Z/20990812T120000Z', $q['dates']);
        $this->assertSame('Prístav 1', $q['location']);
        $this->assertStringStartsWith("https://maps.example.test/x\nRezervácia pre: Janka", $q['details']);
        $this->assertStringContainsString('Poznámka: Devín, potom späť', $q['details']);
    }

    public function test_ics_and_urls(): void
    {
        config(['app.url' => 'https://rez.example.test']);
        $reservation = $this->reservation();
        $calendar = app(ReservationCalendar::class);

        $ics = $calendar->ics($reservation);
        $this->assertStringContainsString("DTSTART:20990812T090000Z\r\n", $ics);
        $this->assertStringContainsString('STATUS:CONFIRMED', $ics);
        // Newlines inside DESCRIPTION are escaped per RFC 5545, commas too.
        $this->assertStringContainsString('Rezervácia pre: Janka\nZdroj: K-CAL Cetus\nPoznámka: Devín\, potom späť', $ics);
        $this->assertStringNotContainsString('LOCATION:', $ics, 'no address configured');

        $this->assertSame("https://rez.example.test/api/v1/reservations/{$reservation->id}/ics", $calendar->icsUrl($reservation));
        $this->assertSame('rezervacia-'.substr($reservation->id, 0, 8).'.ics', $calendar->icsFilename($reservation));
    }
}
