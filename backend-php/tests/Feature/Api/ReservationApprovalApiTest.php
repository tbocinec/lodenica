<?php

namespace Tests\Feature\Api;

use App\Domain\Enums\ResourceType;
use App\Domain\Enums\UserRole;
use App\Mail\ReservationApprovalRequestedMail;
use App\Models\Reservation;
use App\Models\Resource;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The approval workflow end to end (spec §5.2–5.3, REZ-050…060).
 * "gated" = a resource with requiresApproval = true.
 */
class ReservationApprovalApiTest extends TestCase
{
    use RefreshDatabase;

    private Resource $kayak;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        $this->kayak = Resource::create(['identifier' => 'K-1', 'type' => ResourceType::WW_KAYAK, 'name' => 'Kayak 1']);
    }

    /** @param  User[]  $approvers */
    private function gated(array $approvers = []): Resource
    {
        $r = Resource::create([
            'identifier' => 'S-'.bin2hex(random_bytes(2)), 'type' => ResourceType::BOATHOUSE_SPACE,
            'name' => 'Klubovňa', 'requiresApproval' => true,
        ]);
        if ($approvers !== []) {
            $r->approvers()->attach(array_map(fn (User $u) => $u->id, $approvers));
        }

        return $r;
    }

    private function user(UserRole $role = UserRole::MEMBER): User
    {
        $suffix = bin2hex(random_bytes(3));

        return User::create([
            'name' => "User {$suffix}", 'email' => "u{$suffix}@example.test",
            'password' => 'password123', 'role' => $role, 'isActive' => true,
        ]);
    }

    private function book(Resource $r, string $from = '2027-06-01T09:00:00Z', string $to = '2027-06-01T12:00:00Z'): TestResponse
    {
        return $this->postJson('/api/v1/reservations', [
            'resourceId' => $r->id, 'customerName' => 'Ján', 'startsAt' => $from, 'endsAt' => $to,
        ]);
    }

    /* ────────────── Creation (REZ-051, REZ-052, REZ-053) ────────────── */

    public function test_member_booking_of_a_gated_resource_waits_for_approval(): void
    {
        $member = $this->actingAsMember();
        $space = $this->gated();

        $this->book($space)
            ->assertCreated()
            ->assertJsonPath('status', 'PENDING_APPROVAL')
            ->assertJsonPath('createdById', $member->id)
            ->assertJsonPath('decidedAt', null)
            ->assertJsonPath('decisionNote', null);
    }

    public function test_member_booking_of_a_normal_resource_is_confirmed_as_before(): void
    {
        $this->actingAsMember();

        $this->book($this->kayak)->assertCreated()->assertJsonPath('status', 'CONFIRMED');
        Mail::assertNothingSent();
    }

    public function test_anonymous_cannot_book_a_gated_resource(): void
    {
        $this->book($this->gated())
            ->assertStatus(403)
            ->assertJsonPath('code', 'RESERVATION_APPROVAL_MEMBER_REQUIRED');
        $this->assertSame(0, Reservation::count());
    }

    public function test_pending_account_cannot_book_a_gated_resource(): void
    {
        $this->actingAsPending();

        $this->book($this->gated())->assertStatus(403)->assertJsonPath('code', 'RESERVATION_APPROVAL_MEMBER_REQUIRED');
    }

    public function test_a_pending_request_holds_the_slot(): void
    {
        $this->actingAsMember();
        $space = $this->gated();
        $this->book($space)->assertCreated();

        $this->actingAsMember();
        $this->book($space, '2027-06-01T10:00:00Z', '2027-06-01T11:00:00Z')
            ->assertStatus(409)
            ->assertJsonPath('code', 'RESERVATION_OVERLAP');
    }

    public function test_approvers_are_mailed_when_a_request_is_created(): void
    {
        $approver = $this->user();
        $space = $this->gated([$approver]);
        $this->actingAsMember();

        $this->book($space)->assertCreated();

        Mail::assertSent(ReservationApprovalRequestedMail::class, fn ($m) => $m->hasTo($approver->email));
    }

    /* ────────────── PATCH lock (REZ-058) and cancel (REZ-059, REZ-024) ────────────── */

    public function test_patch_cannot_change_status_of_a_pending_reservation(): void
    {
        $this->actingAsMember();
        $id = $this->book($this->gated())->json('id');

        $this->patchJson("/api/v1/reservations/{$id}", ['status' => 'CONFIRMED'])
            ->assertStatus(409)
            ->assertJsonPath('code', 'RESERVATION_STATUS_LOCKED');
        $this->assertSame('PENDING_APPROVAL', Reservation::find($id)->status->value);
    }

    public function test_patch_of_other_fields_keeps_the_request_pending(): void
    {
        $this->actingAsMember();
        $id = $this->book($this->gated())->json('id');

        $this->patchJson("/api/v1/reservations/{$id}", ['note' => 'Prídeme 20 ľudí', 'endsAt' => '2027-06-01T14:00:00Z'])
            ->assertOk()
            ->assertJsonPath('status', 'PENDING_APPROVAL')
            ->assertJsonPath('note', 'Prídeme 20 ľudí');
    }

    public function test_patch_cannot_confirm_on_a_gated_resource(): void
    {
        $this->actingAsMember();
        $space = $this->gated();
        $id = $this->book($space)->json('id');
        $this->patchJson("/api/v1/reservations/{$id}/cancel")->assertOk()->assertJsonPath('status', 'CANCELLED');

        $this->patchJson("/api/v1/reservations/{$id}", ['status' => 'CONFIRMED'])
            ->assertStatus(409)
            ->assertJsonPath('code', 'RESERVATION_STATUS_LOCKED');
    }

    public function test_patch_status_still_works_on_a_normal_resource(): void
    {
        $this->actingAsMember();
        $id = $this->book($this->kayak)->json('id');

        $this->patchJson("/api/v1/reservations/{$id}", ['status' => 'CANCELLED'])->assertOk()->assertJsonPath('status', 'CANCELLED');
        $this->patchJson("/api/v1/reservations/{$id}", ['status' => 'CONFIRMED'])->assertOk()->assertJsonPath('status', 'CONFIRMED');
    }

    public function test_patch_rejects_the_approval_statuses_as_input(): void
    {
        $this->actingAsMember();
        $id = $this->book($this->kayak)->json('id');

        $this->patchJson("/api/v1/reservations/{$id}", ['status' => 'PENDING_APPROVAL'])->assertStatus(400);
        $this->patchJson("/api/v1/reservations/{$id}", ['status' => 'REJECTED'])->assertStatus(400);
    }

    public function test_cancelling_a_pending_request_frees_the_slot(): void
    {
        $this->actingAsMember();
        $space = $this->gated();
        $id = $this->book($space)->json('id');

        $this->patchJson("/api/v1/reservations/{$id}/cancel")->assertOk()->assertJsonPath('status', 'CANCELLED');
        $this->book($space)->assertCreated();
    }

    public function test_cancelling_a_rejected_reservation_is_a_no_op(): void
    {
        $this->actingAsMember();
        $space = $this->gated();
        $id = $this->book($space)->json('id');
        Reservation::whereKey($id)->update(['status' => 'REJECTED']);

        $this->patchJson("/api/v1/reservations/{$id}/cancel")->assertOk()->assertJsonPath('status', 'REJECTED');
    }

    /* ────────────── Visibility ────────────── */

    public function test_decision_note_is_hidden_from_anonymous_readers(): void
    {
        // Anonymous request FIRST — Sanctum::actingAs cannot be undone within a
        // test, and refreshApplication() would drop the in-memory SQLite DB.
        $space = $this->gated();
        $id = Reservation::create([
            'resourceId' => $space->id, 'customerName' => 'Ján',
            'startsAt' => '2027-06-01 09:00:00', 'endsAt' => '2027-06-01 12:00:00',
            'status' => 'REJECTED', 'decisionNote' => 'Plné.',
        ])->id;

        $this->getJson("/api/v1/reservations/{$id}")->assertOk()->assertJsonPath('decisionNote', null);

        $this->actingAsMember();
        $this->getJson("/api/v1/reservations/{$id}")->assertOk()->assertJsonPath('decisionNote', 'Plné.');
    }
}
