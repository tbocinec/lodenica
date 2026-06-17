<?php

namespace Tests\Feature\Api;

use App\Domain\Enums\ResourceType;
use App\Models\Resource;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MyReservationsApiTest extends TestCase
{
    use RefreshDatabase;

    private Resource $kayak;

    protected function setUp(): void
    {
        parent::setUp();
        $this->kayak = Resource::create([
            'identifier' => 'K-1', 'type' => ResourceType::WW_KAYAK, 'name' => 'Kayak 1',
        ]);
    }

    public function test_logged_in_booking_defaults_customer_to_self_and_stamps_creator(): void
    {
        $member = $this->actingAsMember(['name' => 'Janko Member', 'email' => 'janko@example.test']);

        $resp = $this->postJson('/api/v1/reservations', [
            'resourceId' => $this->kayak->id,
            // no customerName / customerContact → defaults to the member
            'startsAt' => '2027-05-10T09:00:00Z',
            'endsAt' => '2027-05-10T12:00:00Z',
        ])->assertCreated()
            ->assertJsonPath('customerName', 'Janko Member')
            ->assertJsonPath('customerContact', 'janko@example.test')
            ->assertJsonPath('createdById', $member->id);
    }

    public function test_member_can_book_for_someone_else(): void
    {
        $member = $this->actingAsMember();

        $this->postJson('/api/v1/reservations', [
            'resourceId' => $this->kayak->id,
            'customerName' => 'Niekto Iný',
            'customerContact' => 'iny@example.test',
            'startsAt' => '2027-05-11T09:00:00Z',
            'endsAt' => '2027-05-11T12:00:00Z',
        ])->assertCreated()
            ->assertJsonPath('customerName', 'Niekto Iný')
            ->assertJsonPath('createdById', $member->id); // booker is still recorded
    }

    public function test_anonymous_booking_has_no_creator(): void
    {
        $this->postJson('/api/v1/reservations', [
            'resourceId' => $this->kayak->id,
            'customerName' => 'Anonym',
            'startsAt' => '2027-05-12T09:00:00Z',
            'endsAt' => '2027-05-12T12:00:00Z',
        ])->assertCreated()
            ->assertJsonPath('createdById', null);
    }

    public function test_mine_returns_only_my_reservations(): void
    {
        $member = $this->actingAsMember();

        // Mine (via the authenticated booking flow).
        $this->postJson('/api/v1/reservations', [
            'resourceId' => $this->kayak->id,
            'startsAt' => '2027-06-01T09:00:00Z',
            'endsAt' => '2027-06-01T12:00:00Z',
        ])->assertCreated();

        // Someone else's booking — created directly so it isn't stamped with
        // the acting member (Sanctum::actingAs stays set for the whole test).
        \App\Models\Reservation::create([
            'resourceId' => $this->kayak->id,
            'createdById' => null,
            'customerName' => 'Anonym',
            'startsAt' => '2027-06-02T09:00:00Z',
            'endsAt' => '2027-06-02T12:00:00Z',
        ]);

        $this->getJson('/api/v1/reservations/mine')
            ->assertOk()
            ->assertJsonPath('total', 1)
            ->assertJsonPath('items.0.createdById', $member->id);
    }

    public function test_mine_requires_auth(): void
    {
        $this->getJson('/api/v1/reservations/mine')->assertStatus(401);
    }
}
