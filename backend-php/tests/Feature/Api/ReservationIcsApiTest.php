<?php

namespace Tests\Feature\Api;

use App\Domain\Enums\ResourceType;
use App\Models\Resource;
use App\Services\ReservationsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReservationIcsApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_ics_returns_calendar_attachment_with_event(): void
    {
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
        $this->assertStringContainsString('UID:'.$reservation->id.'@rezervacie.lodenicakvs.sk', $body);
        $this->assertStringContainsString('DTSTART:20990812T090000Z', $body);
        $this->assertStringContainsString('DTEND:20990812T120000Z', $body);
        $this->assertStringContainsString('SUMMARY:Lodenica KVŠ: K-ICS – P&H Cetus', $body);
        $this->assertStringContainsString('LOCATION:Klub vodných športov Karlova Ves', $body);
        $this->assertStringContainsString('Botanická 20/59', $body);
        $this->assertStringContainsString('841 04 Bratislava-Karlova Ves', $body);
        $this->assertStringContainsString('maps.app.goo.gl/zZwKA168QCeugSxA8', $body);
        // Note + customer fields land in DESCRIPTION as backslash-escaped \n.
        $this->assertStringContainsString('Janka Tester', $body);
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
