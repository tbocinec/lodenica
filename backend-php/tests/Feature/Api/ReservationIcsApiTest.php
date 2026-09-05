<?php

namespace Tests\Feature\Api;

use App\Domain\Enums\ResourceType;
use App\Models\Resource;
use App\Services\ReservationsService;
use App\Services\SiteConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReservationIcsApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_ics_returns_calendar_attachment_with_event(): void
    {
        config(['app.url' => 'https://rezervacie.example.test']);
        app(SiteConfig::class)->update([
            'siteName' => 'Klub Test',
            'address' => 'Prístav 1, 900 01 Mesto',
            'mapsUrl' => 'https://maps.example.test/x',
        ]);
        $resource = Resource::create([
            'identifier' => 'K-ICS',
            'type' => ResourceType::SEA_KAYAK,
            'name' => 'P&H Cetus',
        ]);
        $reservation = app(ReservationsService::class)->create([
            'resourceId' => $resource->id,
            'customerName' => 'Janka Tester',
            'customerContact' => 'janka@example.test',
            'startsAt' => '2099-08-12T09:00:00Z',
            'endsAt' => '2099-08-12T12:00:00Z',
            'note' => 'Trasa: Devín → Bratislava',
        ]);

        $response = $this->get("/api/v1/reservations/{$reservation->id}/ics");
        $response->assertOk()
            ->assertHeader('content-type', 'text/calendar; charset=utf-8');

        $body = $response->getContent();
        $this->assertStringContainsString('BEGIN:VCALENDAR', $body);
        $this->assertStringContainsString('END:VCALENDAR', $body);
        $this->assertStringContainsString('UID:'.$reservation->id.'@rezervacie.example.test', $body);
        $this->assertStringContainsString('PRODID:-//Klub Test//SK', $body);
        $this->assertStringContainsString('DTSTART:20990812T090000Z', $body);
        $this->assertStringContainsString('DTEND:20990812T120000Z', $body);
        $this->assertStringContainsString('SUMMARY:Klub Test: K-ICS – P&H Cetus', $body);
        $this->assertStringContainsString('LOCATION:Prístav 1\\, 900 01 Mesto', $body);
        $this->assertStringContainsString('https://maps.example.test/x', $body);
        // Note + customer fields land in DESCRIPTION as backslash-escaped \n.
        $this->assertStringContainsString('Janka Tester', $body);
    }

    public function test_ics_omits_location_and_maps_when_not_configured(): void
    {
        $resource = Resource::create(['identifier' => 'K-2', 'type' => ResourceType::SEA_KAYAK, 'name' => 'X']);
        $reservation = app(ReservationsService::class)->create([
            'resourceId' => $resource->id, 'customerName' => 'A',
            'startsAt' => '2099-08-12T09:00:00Z', 'endsAt' => '2099-08-12T12:00:00Z',
        ]);

        $body = $this->get("/api/v1/reservations/{$reservation->id}/ics")->getContent();

        $this->assertStringNotContainsString('LOCATION:', $body);
        $this->assertStringNotContainsString('maps.', $body);
        $this->assertStringContainsString('SUMMARY:Lodenica: K-2 – X', $body);
    }

    public function test_ics_returns_404_for_unknown_id(): void
    {
        $this->get('/api/v1/reservations/00000000-0000-0000-0000-000000000000/ics')
            ->assertStatus(404);
    }

    public function test_ics_marks_a_pending_reservation_tentative(): void
    {
        $this->actingAsMember();
        $space = Resource::create(['identifier' => 'S-ICS', 'type' => ResourceType::BOATHOUSE_SPACE, 'name' => 'Klubovňa', 'requiresApproval' => true]);
        $id = $this->postJson('/api/v1/reservations', [
            'resourceId' => $space->id, 'customerName' => 'P',
            'startsAt' => '2099-08-12T09:00:00Z', 'endsAt' => '2099-08-12T12:00:00Z',
        ])->assertCreated()->json('id');

        $this->assertStringContainsString('STATUS:TENTATIVE', $this->get("/api/v1/reservations/{$id}/ics")->getContent());
    }
}
