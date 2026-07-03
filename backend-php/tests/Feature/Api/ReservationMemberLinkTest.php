<?php

namespace Tests\Feature\Api;

use App\Domain\Enums\ResourceType;
use App\Domain\Enums\UserRole;
use App\Models\Reservation;
use App\Models\Resource;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReservationMemberLinkTest extends TestCase
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

    public function test_booking_by_member_is_stamped_with_their_member_id(): void
    {
        $member = $this->actingAsMember(['memberId' => 'KVS-1']);

        $resp = $this->postJson('/api/v1/reservations', [
            'resourceId' => $this->kayak->id,
            'startsAt' => '2030-05-10T09:00:00Z',
            'endsAt' => '2030-05-10T12:00:00Z',
        ])->assertCreated()->json();

        $this->assertSame('KVS-1', Reservation::find($resp['id'])->memberId);

        $this->actingAs($member, 'sanctum');
        $this->getJson('/api/v1/reservations/mine')->assertOk()->assertJsonPath('total', 1);
    }

    public function test_mine_includes_reservations_tagged_with_my_member_id_even_if_created_by_someone_else(): void
    {
        // A reservation tagged with member ID KVS-9, created by no/other account.
        Reservation::create([
            'resourceId' => $this->kayak->id,
            'createdById' => null,
            'memberId' => 'KVS-9',
            'customerName' => 'Pôvodný člen',
            'startsAt' => '2030-06-01T09:00:00Z',
            'endsAt' => '2030-06-01T12:00:00Z',
        ]);

        // A (possibly new) account that now holds KVS-9 sees it as "mine"
        // (matched by member ID, not createdById). memberId itself is NOT
        // exposed on the reservation resource — it's internal.
        $this->actingAsMember(['email' => 'newholder@example.test', 'memberId' => 'KVS-9']);
        $this->getJson('/api/v1/reservations/mine')
            ->assertOk()
            ->assertJsonPath('total', 1)
            ->assertJsonPath('items.0.customerName', 'Pôvodný člen');
    }

    public function test_assigning_member_id_backfills_the_users_existing_reservations(): void
    {
        $member = $this->actingAsMember(['email' => 'later@example.test']); // no memberId yet

        $r = $this->postJson('/api/v1/reservations', [
            'resourceId' => $this->kayak->id,
            'startsAt' => '2030-07-01T09:00:00Z',
            'endsAt' => '2030-07-01T12:00:00Z',
        ])->assertCreated()->json();
        $this->assertNull(Reservation::find($r['id'])->memberId);

        // Admin assigns a member ID → the user's past booking gets tagged.
        $this->actingAsAdmin();
        $this->patchJson("/api/v1/users/{$member->id}", ['memberId' => 'KVS-42'])->assertOk();

        $this->assertSame('KVS-42', Reservation::find($r['id'])->memberId);
    }

    public function test_admin_can_reassign_reservation_to_a_member(): void
    {
        $owner = User::create([
            'name' => 'Nový vlastník', 'email' => 'owner@example.test',
            'password' => 'password123', 'role' => UserRole::MEMBER, 'isActive' => true,
            'memberId' => 'KVS-500',
        ]);
        $r = Reservation::create([
            'resourceId' => $this->kayak->id, 'createdById' => null, 'memberId' => null,
            'customerName' => 'X', 'startsAt' => '2030-09-01T09:00:00Z', 'endsAt' => '2030-09-01T12:00:00Z',
        ]);

        $this->actingAsAdmin();
        $this->patchJson("/api/v1/reservations/{$r->id}", ['memberId' => 'KVS-500'])->assertOk();

        $r->refresh();
        $this->assertSame('KVS-500', $r->memberId);
        $this->assertSame($owner->id, $r->createdById); // linked to the matching user
    }

    public function test_non_admin_cannot_reassign_reservation_owner(): void
    {
        $r = Reservation::create([
            'resourceId' => $this->kayak->id, 'memberId' => 'KVS-1',
            'customerName' => 'X', 'startsAt' => '2030-09-02T09:00:00Z', 'endsAt' => '2030-09-02T12:00:00Z',
        ]);
        $this->actingAsMember();
        $this->patchJson("/api/v1/reservations/{$r->id}", ['memberId' => 'KVS-999', 'customerName' => 'Y'])
            ->assertOk();

        // customerName change applied, but memberId reassignment was ignored.
        $r->refresh();
        $this->assertSame('Y', $r->customerName);
        $this->assertSame('KVS-1', $r->memberId);
    }

    public function test_confirming_pending_member_with_id_backfills_their_reservations(): void
    {
        // A PENDING user who already made a booking (PENDING can create).
        $pending = User::create([
            'name' => 'Č', 'email' => 'pend-book@example.test',
            'password' => 'password123', 'role' => UserRole::PENDING, 'isActive' => true,
        ]);
        $this->actingAs($pending, 'sanctum');
        $r = $this->postJson('/api/v1/reservations', [
            'resourceId' => $this->kayak->id,
            'startsAt' => '2030-08-01T09:00:00Z',
            'endsAt' => '2030-08-01T12:00:00Z',
        ])->assertCreated()->json();

        $this->actingAsAdmin();
        $this->postJson("/api/v1/users/{$pending->id}/confirm", ['memberId' => 'KVS-7'])->assertOk();

        $this->assertSame('KVS-7', Reservation::find($r['id'])->memberId);
    }
}
