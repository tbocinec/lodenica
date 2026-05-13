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
            ->assertJsonPath('data.status', 'CONFIRMED')
            ->assertJsonPath('data.resourceId', $this->kayak->id);
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
        $id = $first->json('data.id');

        $this->patchJson("/api/v1/reservations/{$id}/cancel")
            ->assertOk()
            ->assertJsonPath('data.status', 'CANCELLED');

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
}
