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

    public function test_index_with_mine_filter_returns_only_my_reservations(): void
    {
        $member = $this->actingAsMember();

        $this->postJson('/api/v1/reservations', [
            'resourceId' => $this->kayak->id,
            'startsAt' => '2027-07-01T09:00:00Z',
            'endsAt' => '2027-07-01T12:00:00Z',
        ])->assertCreated();

        \App\Models\Reservation::create([
            'resourceId' => $this->kayak->id,
            'createdById' => null,
            'customerName' => 'Niekto Iný',
            'startsAt' => '2027-07-02T09:00:00Z',
            'endsAt' => '2027-07-02T12:00:00Z',
        ]);

        // Without the flag the list is everybody's.
        $this->getJson('/api/v1/reservations')->assertOk()->assertJsonPath('total', 2);

        $this->getJson('/api/v1/reservations?mine=1')
            ->assertOk()
            ->assertJsonPath('total', 1)
            ->assertJsonPath('items.0.createdById', $member->id);
    }

    public function test_index_mine_filter_returns_nothing_for_anonymous(): void
    {
        \App\Models\Reservation::create([
            'resourceId' => $this->kayak->id,
            'createdById' => null,
            'customerName' => 'Niekto Iný',
            'startsAt' => '2027-07-03T09:00:00Z',
            'endsAt' => '2027-07-03T12:00:00Z',
        ]);

        // Nobody is logged in, so "mine" is nobody's — not everybody's.
        $this->getJson('/api/v1/reservations?mine=1')
            ->assertOk()
            ->assertJsonPath('total', 0)
            ->assertJsonCount(0, 'items');
    }

    public function test_index_mine_filter_combines_with_a_from_bound(): void
    {
        $this->actingAsMember();

        foreach ([
            ['2027-07-31T09:00:00Z', '2027-07-31T12:00:00Z'], // over
            ['2027-08-01T08:00:00Z', '2027-08-01T12:00:00Z'], // in progress at 10:00
            ['2027-08-05T09:00:00Z', '2027-08-05T12:00:00Z'], // upcoming
        ] as [$startsAt, $endsAt]) {
            $this->postJson('/api/v1/reservations', [
                'resourceId' => $this->kayak->id,
                'startsAt' => $startsAt,
                'endsAt' => $endsAt,
            ])->assertCreated();
        }

        // What the dashboard asks for: mine, still running or yet to start.
        $items = $this->getJson('/api/v1/reservations?mine=1&from=2027-08-01T10:00:00Z')
            ->assertOk()
            ->assertJsonPath('total', 2)
            ->json('items');

        // Chronological — the nearest booking first.
        $this->assertSame('2027-08-01T08:00:00+00:00', $items[0]['startsAt']);
        $this->assertSame('2027-08-05T09:00:00+00:00', $items[1]['startsAt']);
    }


    /**
     * Axios (and most HTTP clients) serialise a boolean query param as
     * the string "true" — which Laravel's `boolean` rule rejects on its
     * own. The SPA sends exactly this, so it is the shape that counts.
     */
    public function test_index_mine_filter_accepts_the_string_true(): void
    {
        $member = $this->actingAsMember();

        $this->postJson('/api/v1/reservations', [
            'resourceId' => $this->kayak->id,
            'startsAt' => '2027-09-01T09:00:00Z',
            'endsAt' => '2027-09-01T12:00:00Z',
        ])->assertCreated();

        \App\Models\Reservation::create([
            'resourceId' => $this->kayak->id,
            'createdById' => null,
            'customerName' => 'Niekto Iný',
            'startsAt' => '2027-09-02T09:00:00Z',
            'endsAt' => '2027-09-02T12:00:00Z',
        ]);

        $this->getJson('/api/v1/reservations?mine=true')
            ->assertOk()
            ->assertJsonPath('total', 1)
            ->assertJsonPath('items.0.createdById', $member->id);

        // …and "false" is a filter that was switched off, not a 400.
        $this->getJson('/api/v1/reservations?mine=false')
            ->assertOk()
            ->assertJsonPath('total', 2);
    }
}
