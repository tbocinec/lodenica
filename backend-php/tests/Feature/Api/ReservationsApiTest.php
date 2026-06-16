<?php

namespace Tests\Feature\Api;

use App\Domain\Enums\ResourceType;
use App\Models\Resource;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReservationsApiTest extends TestCase
{
    use RefreshDatabase;

    private Resource $kayak;

    protected function setUp(): void
    {
        parent::setUp();
        $this->kayak = Resource::create([
            'identifier' => 'K-1',
            'type' => ResourceType::WW_KAYAK,
            'name' => 'Kayak 1',
        ]);
    }

    public function test_create_reservation(): void
    {
        $response = $this->postJson('/api/v1/reservations', [
            'resourceId' => $this->kayak->id,
            'customerName' => 'Ján',
            'startsAt' => '2026-05-10T09:00:00Z',
            'endsAt' => '2026-05-10T12:00:00Z',
        ]);

        $response->assertCreated()
            ->assertJsonPath('status', 'CONFIRMED')
            ->assertJsonPath('resourceId', $this->kayak->id);
    }

    public function test_create_returns_409_on_overlap(): void
    {
        $this->postJson('/api/v1/reservations', [
            'resourceId' => $this->kayak->id,
            'customerName' => 'First',
            'startsAt' => '2026-05-10T09:00:00Z',
            'endsAt' => '2026-05-10T12:00:00Z',
        ])->assertCreated();

        $this->postJson('/api/v1/reservations', [
            'resourceId' => $this->kayak->id,
            'customerName' => 'Second',
            'startsAt' => '2026-05-10T10:00:00Z',
            'endsAt' => '2026-05-10T11:00:00Z',
        ])
            ->assertStatus(409)
            ->assertJsonPath('code', 'RESERVATION_OVERLAP');
    }

    public function test_create_returns_400_on_invalid_range(): void
    {
        $this->postJson('/api/v1/reservations', [
            'resourceId' => $this->kayak->id,
            'customerName' => 'Ján',
            'startsAt' => '2026-05-10T12:00:00Z',
            'endsAt' => '2026-05-10T09:00:00Z',
        ])
            ->assertStatus(400)
            ->assertJsonPath('code', 'RESERVATION_INVALID_RANGE');
    }

    public function test_create_returns_400_on_inactive_resource(): void
    {
        $inactive = Resource::create([
            'identifier' => 'K-OFF',
            'type' => ResourceType::WW_KAYAK,
            'name' => 'Off',
            'isActive' => false,
        ]);
        $this->postJson('/api/v1/reservations', [
            'resourceId' => $inactive->id,
            'customerName' => 'Ján',
            'startsAt' => '2026-05-10T09:00:00Z',
            'endsAt' => '2026-05-10T10:00:00Z',
        ])
            ->assertStatus(400)
            ->assertJsonPath('code', 'RESERVATION_RESOURCE_INACTIVE');
    }

    public function test_cancel_then_create_same_slot_succeeds(): void
    {
        $first = $this->postJson('/api/v1/reservations', [
            'resourceId' => $this->kayak->id,
            'customerName' => 'First',
            'startsAt' => '2026-05-10T09:00:00Z',
            'endsAt' => '2026-05-10T12:00:00Z',
        ])->assertCreated();
        $id = $first->json('id');

        // Cancel requires a confirmed member (see
        // docs/AUTH-AND-PERMISSIONS.md). Anonymous booking still
        // works for the second create below.
        $this->actingAsMember();
        $this->patchJson("/api/v1/reservations/{$id}/cancel")
            ->assertOk()
            ->assertJsonPath('status', 'CANCELLED');

        $this->postJson('/api/v1/reservations', [
            'resourceId' => $this->kayak->id,
            'customerName' => 'Second',
            'startsAt' => '2026-05-10T09:00:00Z',
            'endsAt' => '2026-05-10T12:00:00Z',
        ])->assertCreated();
    }

    public function test_back_to_back_bookings_are_allowed(): void
    {
        $this->postJson('/api/v1/reservations', [
            'resourceId' => $this->kayak->id,
            'customerName' => 'First',
            'startsAt' => '2026-05-10T09:00:00Z',
            'endsAt' => '2026-05-10T12:00:00Z',
        ])->assertCreated();

        $this->postJson('/api/v1/reservations', [
            'resourceId' => $this->kayak->id,
            'customerName' => 'Second',
            'startsAt' => '2026-05-10T12:00:00Z',
            'endsAt' => '2026-05-10T15:00:00Z',
        ])->assertCreated();
    }

    public function test_customer_name_and_contact_are_hidden_for_anonymous_readers(): void
    {
        // Both customerName and customerContact are private to confirmed
        // members. See docs/AUTH-AND-PERMISSIONS.md.
        $reservation = $this->postJson('/api/v1/reservations', [
            'resourceId' => $this->kayak->id,
            'customerName' => 'Janka',
            'customerContact' => 'janka@example.test',
            'startsAt' => '2099-08-10T09:00:00Z',
            'endsAt' => '2099-08-10T12:00:00Z',
        ])->assertCreated()->json();

        $id = $reservation['id'];

        // Anonymous list — no name or contact leakage
        $this->getJson('/api/v1/reservations?pageSize=200')
            ->assertOk()
            ->assertJsonPath('items.0.customerName', null)
            ->assertJsonPath('items.0.customerContact', null);

        // Anonymous detail / show — same
        $this->getJson("/api/v1/reservations/{$id}")
            ->assertOk()
            ->assertJsonPath('customerName', null)
            ->assertJsonPath('customerContact', null);

        // Anonymous PATCH is gated now — should be 401
        $this->patchJson("/api/v1/reservations/{$id}", [
            'note' => 'Anon edit attempt',
        ])->assertStatus(401);

        // The stored row is untouched
        $this->assertSame('Janka',
            \App\Models\Reservation::find($id)->customerName);
        $this->assertSame('janka@example.test',
            \App\Models\Reservation::find($id)->customerContact);

        // PENDING users see anonymised data too
        $this->actingAsPending();
        $this->getJson("/api/v1/reservations/{$id}")
            ->assertOk()
            ->assertJsonPath('customerName', null)
            ->assertJsonPath('customerContact', null);

        // Authenticated member sees the real values
        $this->actingAsMember();
        $this->getJson("/api/v1/reservations/{$id}")
            ->assertOk()
            ->assertJsonPath('customerName', 'Janka')
            ->assertJsonPath('customerContact', 'janka@example.test');
    }

    public function test_search_matches_resource_identifier_and_name(): void
    {
        // Distinct boats so we can confirm the search dragnet picks up
        // the right one via the joined resource — not just via the
        // reservation's own free-text fields.
        $burn = Resource::create([
            'identifier' => 'K-007',
            'type' => ResourceType::WW_KAYAK,
            'name' => 'Pyranha Burn',
        ]);
        $cetus = Resource::create([
            'identifier' => 'K-008',
            'type' => ResourceType::SEA_KAYAK,
            'name' => 'P&H Cetus',
        ]);

        $this->postJson('/api/v1/reservations', [
            'resourceId' => $burn->id,
            'customerName' => 'Anna',
            'startsAt' => '2099-07-10T09:00:00Z',
            'endsAt' => '2099-07-10T12:00:00Z',
        ])->assertCreated();
        $this->postJson('/api/v1/reservations', [
            'resourceId' => $cetus->id,
            'customerName' => 'Bohuš',
            'startsAt' => '2099-07-11T09:00:00Z',
            'endsAt' => '2099-07-11T12:00:00Z',
        ])->assertCreated();

        // Search is server-side and matches resource fields + customer
        // free-text. As a confirmed member we also see the customerName
        // values directly; anon would see total counts only.
        $this->actingAsMember();

        // Identifier match (case-insensitive, partial)
        $this->getJson('/api/v1/reservations?search=K-007')
            ->assertOk()
            ->assertJsonPath('total', 1)
            ->assertJsonPath('items.0.customerName', 'Anna');

        // Name match
        $this->getJson('/api/v1/reservations?search=pyranha')
            ->assertOk()
            ->assertJsonPath('total', 1)
            ->assertJsonPath('items.0.customerName', 'Anna');

        // Customer name still works (existing behaviour preserved)
        $this->getJson('/api/v1/reservations?search=bohu')
            ->assertOk()
            ->assertJsonPath('total', 1)
            ->assertJsonPath('items.0.customerName', 'Bohuš');
    }
}
