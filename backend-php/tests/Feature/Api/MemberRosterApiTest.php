<?php

namespace Tests\Feature\Api;

use App\Domain\Enums\UserRole;
use App\Models\MemberRosterEntry;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MemberRosterApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_create_and_list_roster_entries(): void
    {
        $this->actingAsAdmin();

        $this->postJson('/api/v1/member-roster', [
            'email' => 'Roster@Example.test', 'memberId' => 'KVS-1', 'name' => 'Ján',
        ])->assertCreated()
            ->assertJsonPath('email', 'roster@example.test')
            ->assertJsonPath('memberId', 'KVS-1');

        $this->getJson('/api/v1/member-roster')
            ->assertOk()
            ->assertJsonPath('total', 1)
            ->assertJsonPath('items.0.email', 'roster@example.test');
    }

    public function test_create_rejects_duplicate_email(): void
    {
        $this->actingAsAdmin();
        MemberRosterEntry::create(['email' => 'dup@example.test', 'memberId' => 'A']);

        $this->postJson('/api/v1/member-roster', ['email' => 'dup@example.test'])
            ->assertStatus(409);
    }

    public function test_roster_is_admin_only(): void
    {
        $this->actingAsMember();
        $this->getJson('/api/v1/member-roster')->assertStatus(403);
        $this->postJson('/api/v1/member-roster', ['email' => 'x@example.test'])->assertStatus(403);
    }

    public function test_import_parses_id_name_email_order(): void
    {
        $this->actingAsAdmin();

        $csv = "id,meno,email\nKVS-5,Eva,eva@example.test\nKVS-6,Zara,zara@example.test\n";

        $this->postJson('/api/v1/member-roster/import', ['csv' => $csv])
            ->assertCreated()
            ->assertJsonPath('createdCount', 2);

        $this->assertDatabaseHas('member_roster', [
            'email' => 'eva@example.test', 'memberId' => 'KVS-5', 'name' => 'Eva',
        ]);
    }

    public function test_registration_with_rostered_email_is_auto_approved_with_member_id(): void
    {
        MemberRosterEntry::create(['email' => 'known@example.test', 'memberId' => 'KVS-99', 'name' => 'Roster Name']);

        $this->postJson('/api/v1/auth/register', [
            'name' => 'Real Name',
            'email' => 'known@example.test',
            'password' => 'password123',
            'dataConsent' => true, 'rulesAck' => true,
        ])->assertCreated()
            ->assertJsonPath('user.role', 'MEMBER');

        $user = User::where('email', 'known@example.test')->first();
        $this->assertSame(UserRole::MEMBER, $user->role);
        $this->assertSame('KVS-99', $user->memberId);
        $this->assertSame('Real Name', $user->name); // roster name ignored

        // Roster entry stamped with who registered.
        $entry = MemberRosterEntry::where('email', 'known@example.test')->first();
        $this->assertSame($user->id, $entry->registeredUserId);
        $this->assertNotNull($entry->registeredAt);
    }

    public function test_registration_without_roster_match_stays_pending(): void
    {
        $this->postJson('/api/v1/auth/register', [
            'name' => 'Nobody',
            'email' => 'nobody@example.test',
            'password' => 'password123',
            'dataConsent' => true, 'rulesAck' => true,
        ])->assertCreated()
            ->assertJsonPath('user.role', 'PENDING');
    }

    public function test_admin_can_delete_entry(): void
    {
        $this->actingAsAdmin();
        $entry = MemberRosterEntry::create(['email' => 'del@example.test']);

        $this->deleteJson("/api/v1/member-roster/{$entry->id}")->assertNoContent();
        $this->assertDatabaseMissing('member_roster', ['id' => $entry->id]);
    }
}
