<?php

namespace Tests\Feature\Api;

use App\Domain\Enums\UserRole;
use App\Mail\AccountInvitationMail;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class MemberIdApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_member_id_is_admin_only_in_responses(): void
    {
        $admin = $this->actingAsAdmin();

        $created = $this->postJson('/api/v1/users', [
            'name' => 'S ID', 'email' => 'sid@example.test',
            'password' => 'password123', 'role' => 'MEMBER', 'memberId' => 'KVS-001',
        ])->assertCreated()->assertJsonPath('memberId', 'KVS-001')->json();

        // Admin listing sees it.
        $this->getJson('/api/v1/users/'.$created['id'])
            ->assertOk()->assertJsonPath('memberId', 'KVS-001');

        // The member themselves does NOT see their own memberId.
        $member = User::find($created['id']);
        \Laravel\Sanctum\Sanctum::actingAs($member, ['*']);
        $this->getJson('/api/v1/auth/me')
            ->assertOk()
            ->assertJsonPath('memberId', null);
    }

    public function test_registration_has_no_member_id(): void
    {
        $this->postJson('/api/v1/auth/register', [
            'name' => 'Reg', 'email' => 'reg-noid@example.test', 'password' => 'tajneheslo123',
            'dataConsent' => true, 'rulesAck' => true,
        ])->assertCreated();
        $this->assertNull(User::where('email', 'reg-noid@example.test')->first()->memberId);
    }

    public function test_confirm_assigns_member_id(): void
    {
        Mail::fake();
        $this->actingAsAdmin();
        $pending = User::create([
            'name' => 'Č', 'email' => 'pend-id@example.test',
            'password' => 'password123', 'role' => UserRole::PENDING, 'isActive' => true,
        ]);

        $this->postJson("/api/v1/users/{$pending->id}/confirm", ['memberId' => 'KVS-777'])
            ->assertOk()
            ->assertJsonPath('role', 'MEMBER')
            ->assertJsonPath('memberId', 'KVS-777');

        $this->assertSame('KVS-777', $pending->fresh()->memberId);
    }

    public function test_member_id_must_be_unique_on_update(): void
    {
        $this->actingAsAdmin();
        $a = User::create(['name' => 'A', 'email' => 'a@example.test', 'password' => 'password123', 'role' => UserRole::MEMBER, 'isActive' => true, 'memberId' => 'DUP-1']);
        $b = User::create(['name' => 'B', 'email' => 'b@example.test', 'password' => 'password123', 'role' => UserRole::MEMBER, 'isActive' => true]);

        $this->patchJson("/api/v1/users/{$b->id}", ['memberId' => 'DUP-1'])
            ->assertStatus(400)
            ->assertJsonPath('code', 'VALIDATION_ERROR');

        $this->assertNull($b->fresh()->memberId);
    }

    public function test_member_id_can_be_cleared_with_empty_string(): void
    {
        $this->actingAsAdmin();
        $u = User::create(['name' => 'U', 'email' => 'u@example.test', 'password' => 'password123', 'role' => UserRole::MEMBER, 'isActive' => true, 'memberId' => 'KVS-9']);

        $this->patchJson("/api/v1/users/{$u->id}", ['memberId' => ''])
            ->assertOk()
            ->assertJsonPath('memberId', null);
    }

    public function test_invite_with_member_id(): void
    {
        Mail::fake();
        $this->actingAsAdmin();

        $this->postJson('/api/v1/users/invite', [
            'name' => 'Pozvaný', 'email' => 'inv-id@example.test', 'memberId' => 'KVS-555',
        ])->assertCreated()->assertJsonPath('memberId', 'KVS-555');

        Mail::assertSent(AccountInvitationMail::class);
    }

    public function test_bulk_import_with_member_id_column_and_duplicate(): void
    {
        Mail::fake();
        $this->actingAsAdmin();
        // Pre-existing holder of KVS-100.
        User::create(['name' => 'X', 'email' => 'x@example.test', 'password' => 'password123', 'role' => UserRole::MEMBER, 'isActive' => true, 'memberId' => 'KVS-100']);

        $csv = "id,meno,email\n"
            ."KVS-101,Ján Nový,jan-new@example.test\n"
            ."KVS-100,Eva Dup,eva-dup@example.test\n"; // dup id → invalid

        $this->postJson('/api/v1/users/import', ['csv' => $csv])
            ->assertCreated()
            ->assertJsonPath('createdCount', 1)
            ->assertJsonPath('invalidCount', 1);

        $this->assertSame('KVS-101', User::where('email', 'jan-new@example.test')->first()->memberId);
        $this->assertNull(User::where('email', 'eva-dup@example.test')->first()); // not created
    }
}
