<?php

namespace Tests\Feature\Api;

use App\Domain\Enums\UserRole;
use App\Mail\PendingMemberNotificationMail;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class AuthRegistrationApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_registration_notifies_admin_of_pending_member(): void
    {
        Mail::fake();

        $this->postJson('/api/v1/auth/register', [
            'name' => 'Čakateľ',
            'email' => 'waiting@example.test',
            'password' => 'tajneheslo123',
            'privacyAck' => true,
        ])->assertCreated();

        Mail::assertSent(PendingMemberNotificationMail::class, fn ($m) => $m->memberEmail === 'waiting@example.test');
    }

    public function test_public_registration_creates_pending_account_and_logs_in(): void
    {
        $resp = $this->postJson('/api/v1/auth/register', [
            'name' => 'Nový Pádlič',
            'email' => 'novy@example.test',
            'password' => 'tajneheslo123',
            'privacyAck' => true,
        ])->assertCreated()
            ->assertJsonPath('user.role', 'PENDING')
            ->assertJsonStructure(['token', 'user' => ['id', 'email', 'role']]);

        $this->assertNotEmpty($resp->json('token'));

        $user = User::where('email', 'novy@example.test')->firstOrFail();
        $this->assertSame(UserRole::PENDING, $user->role);
        $this->assertTrue((bool) $user->isActive);
    }

    public function test_registration_role_cannot_be_forced_by_client(): void
    {
        // Even if a client sends role=ADMIN it is ignored — the controller
        // always forces PENDING.
        $this->postJson('/api/v1/auth/register', [
            'name' => 'Sneaky',
            'email' => 'sneaky@example.test',
            'password' => 'tajneheslo123',
            'privacyAck' => true,
            'role' => 'ADMIN',
        ])->assertCreated()
            ->assertJsonPath('user.role', 'PENDING');
    }

    public function test_registration_rejects_duplicate_email(): void
    {
        User::create([
            'name' => 'Existing',
            'email' => 'dup@example.test',
            'password' => 'password123',
            'role' => UserRole::MEMBER,
            'isActive' => true,
        ]);

        $this->postJson('/api/v1/auth/register', [
            'name' => 'Another',
            'email' => 'dup@example.test',
            'password' => 'tajneheslo123',
            'privacyAck' => true,
        ])->assertStatus(400)
            ->assertJsonPath('code', 'VALIDATION_ERROR');
    }

    public function test_registration_normalizes_email_case(): void
    {
        $this->postJson('/api/v1/auth/register', [
            'name' => 'Case',
            'email' => 'MixedCase@Example.TEST',
            'password' => 'tajneheslo123',
            'privacyAck' => true,
        ])->assertCreated();

        $this->assertDatabaseHas('users', ['email' => 'mixedcase@example.test']);
    }

    public function test_registration_requires_the_mandatory_gdpr_checkbox(): void
    {
        $this->postJson('/api/v1/auth/register', [
            'name' => 'No Ack', 'email' => 'noack@example.test',
            'password' => 'tajneheslo123', 'privacyAck' => false, 'dataConsent' => true,
        ])->assertStatus(400)->assertJsonPath('code', 'VALIDATION_ERROR');

        $this->assertDatabaseMissing('users', ['email' => 'noack@example.test']);
    }

    public function test_registration_stores_both_consents(): void
    {
        $this->postJson('/api/v1/auth/register', [
            'name' => 'Consent', 'email' => 'consent@example.test',
            'password' => 'tajneheslo123', 'privacyAck' => true, 'dataConsent' => false,
        ])->assertCreated();

        $user = User::where('email', 'consent@example.test')->firstOrFail();
        $this->assertTrue((bool) $user->privacyAck);
        $this->assertFalse((bool) $user->dataConsent);
    }

    public function test_data_consent_defaults_to_true_when_omitted(): void
    {
        $this->postJson('/api/v1/auth/register', [
            'name' => 'Default', 'email' => 'default-consent@example.test',
            'password' => 'tajneheslo123', 'privacyAck' => true,
        ])->assertCreated();

        $this->assertTrue((bool) User::where('email', 'default-consent@example.test')->firstOrFail()->dataConsent);
    }
}
