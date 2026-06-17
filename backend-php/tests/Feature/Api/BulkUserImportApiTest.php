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

        $this->assertDatabaseHas('users', ['email' => 'jan@example.test', 'role' => UserRole::PENDING->value]);
        $this->assertDatabaseHas('users', ['email' => 'eva@example.test']);

        Mail::assertSent(AccountInvitationMail::class, 2);
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
}
