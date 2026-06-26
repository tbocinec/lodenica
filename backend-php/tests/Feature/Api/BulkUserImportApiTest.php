<?php

namespace Tests\Feature\Api;

use App\Domain\Enums\UserRole;
use App\Mail\AccountInvitationMail;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class BulkUserImportApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_bulk_import_users_and_invitations_are_sent(): void
    {
        Mail::fake();
        $this->actingAsAdmin();

        $csv = "meno,email\nJán Novák,jan@example.test\nEva Malá,eva@example.test\n";

        $this->postJson('/api/v1/users/import', ['csv' => $csv])
            ->assertCreated()
            ->assertJsonPath('createdCount', 2)
            ->assertJsonPath('skippedCount', 0)
            ->assertJsonPath('invalidCount', 0);

        // Admin-invited accounts are auto-confirmed (MEMBER), not PENDING.
        $this->assertDatabaseHas('users', ['email' => 'jan@example.test', 'role' => UserRole::MEMBER->value]);
        $this->assertDatabaseHas('users', ['email' => 'eva@example.test', 'role' => UserRole::MEMBER->value]);

        Mail::assertSent(AccountInvitationMail::class, 2);
    }

    public function test_bulk_import_parses_id_name_email_order_and_creates_inactive(): void
    {
        Mail::fake();
        $this->actingAsAdmin();

        // Documented order: id, meno, email.
        $csv = "id,meno,email\nKVS-7,Ján Novák,jan7@example.test\n";

        $this->postJson('/api/v1/users/import', ['csv' => $csv])
            ->assertCreated()
            ->assertJsonPath('createdCount', 1);

        $this->assertDatabaseHas('users', [
            'email' => 'jan7@example.test',
            'name' => 'Ján Novák',
            'memberId' => 'KVS-7',
            'role' => UserRole::MEMBER->value,
            // Inactive until they accept the invite + set a password.
            'isActive' => false,
        ]);
    }

    public function test_bulk_import_skips_existing_emails_and_flags_invalid_rows(): void
    {
        Mail::fake();
        $this->actingAsAdmin();

        User::create([
            'name' => 'Existing', 'email' => 'dup@example.test',
            'password' => 'password123', 'role' => UserRole::MEMBER, 'isActive' => true,
        ]);

        $csv = "dup@example.test\nnot-an-email\nfresh@example.test\n";

        $this->postJson('/api/v1/users/import', ['csv' => $csv])
            ->assertCreated()
            ->assertJsonPath('createdCount', 1)
            ->assertJsonPath('skippedCount', 1)
            ->assertJsonPath('invalidCount', 1);

        Mail::assertSent(AccountInvitationMail::class, 1);
    }

    public function test_bulk_import_is_admin_only(): void
    {
        $this->actingAsMember();
        $this->postJson('/api/v1/users/import', ['csv' => 'a@b.test'])
            ->assertStatus(403);
    }

    public function test_bulk_import_requires_auth(): void
    {
        $this->postJson('/api/v1/users/import', ['csv' => 'a@b.test'])
            ->assertStatus(401);
    }

    public function test_admin_can_invite_single_member_auto_confirmed(): void
    {
        Mail::fake();
        $this->actingAsAdmin();

        $this->postJson('/api/v1/users/invite', [
            'name' => 'Pozvaný Člen',
            'email' => 'pozvany@example.test',
        ])->assertCreated()
            ->assertJsonPath('role', 'MEMBER')
            ->assertJsonPath('email', 'pozvany@example.test');

        $this->assertDatabaseHas('users', [
            'email' => 'pozvany@example.test',
            'role' => UserRole::MEMBER->value,
            // Invited members start inactive until they set a password.
            'isActive' => false,
        ]);
        Mail::assertSent(AccountInvitationMail::class, fn ($m) => $m->hasTo('pozvany@example.test'));
    }

    public function test_invite_rejects_duplicate_email(): void
    {
        Mail::fake();
        $this->actingAsAdmin();
        User::create([
            'name' => 'X', 'email' => 'dup2@example.test',
            'password' => 'password123', 'role' => UserRole::MEMBER, 'isActive' => true,
        ]);

        $this->postJson('/api/v1/users/invite', [
            'name' => 'Y', 'email' => 'dup2@example.test',
        ])->assertStatus(400)->assertJsonPath('code', 'VALIDATION_ERROR');

        Mail::assertNothingSent();
    }

    public function test_invite_is_admin_only(): void
    {
        $this->actingAsMember();
        $this->postJson('/api/v1/users/invite', ['name' => 'Z', 'email' => 'z@example.test'])
            ->assertStatus(403);
    }
}
