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

    /** Valid registration payload (consents satisfied) + any overrides. */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Nový Pádlič',
            'email' => 'novy@example.test',
            'password' => 'tajneheslo123',
            'dataConsent' => true,
            'rulesAck' => true,
        ], $overrides);
    }

    public function test_registration_notifies_admin_of_pending_member(): void
    {
        Mail::fake();

        $this->postJson('/api/v1/auth/register', $this->payload([
            'name' => 'Čakateľ', 'email' => 'waiting@example.test',
        ]))->assertCreated();

        Mail::assertSent(PendingMemberNotificationMail::class, fn ($m) => $m->memberEmail === 'waiting@example.test');
    }

    public function test_public_registration_creates_pending_account_and_logs_in(): void
    {
        $resp = $this->postJson('/api/v1/auth/register', $this->payload())
            ->assertCreated()
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
        $this->postJson('/api/v1/auth/register', $this->payload([
            'email' => 'sneaky@example.test', 'role' => 'ADMIN',
        ]))->assertCreated()
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

        $this->postJson('/api/v1/auth/register', $this->payload([
            'email' => 'dup@example.test',
        ]))->assertStatus(400)
            ->assertJsonPath('code', 'VALIDATION_ERROR');
    }

    public function test_registration_normalizes_email_case(): void
    {
        $this->postJson('/api/v1/auth/register', $this->payload([
            'email' => 'MixedCase@Example.TEST',
        ]))->assertCreated();

        $this->assertDatabaseHas('users', ['email' => 'mixedcase@example.test']);
    }

    public function test_registration_requires_operating_rules_acknowledgement(): void
    {
        $this->postJson('/api/v1/auth/register', $this->payload([
            'email' => 'norules@example.test', 'rulesAck' => false,
        ]))->assertStatus(400)->assertJsonPath('code', 'VALIDATION_ERROR');

        $this->assertDatabaseMissing('users', ['email' => 'norules@example.test']);
    }

    public function test_registration_requires_an_explicit_data_consent_choice(): void
    {
        // dataConsent omitted entirely → rejected (must pick yes or no).
        $payload = $this->payload(['email' => 'nochoice@example.test']);
        unset($payload['dataConsent']);

        $this->postJson('/api/v1/auth/register', $payload)
            ->assertStatus(400)->assertJsonPath('code', 'VALIDATION_ERROR');

        $this->assertDatabaseMissing('users', ['email' => 'nochoice@example.test']);
    }

    public function test_registration_stores_consents_and_timestamps(): void
    {
        $this->postJson('/api/v1/auth/register', $this->payload([
            'email' => 'consent@example.test', 'dataConsent' => false,
        ]))->assertCreated();

        $user = User::where('email', 'consent@example.test')->firstOrFail();
        $this->assertTrue((bool) $user->privacyAck);     // forced true (informational)
        $this->assertFalse((bool) $user->dataConsent);   // chose "neudeľujem"
        $this->assertTrue((bool) $user->rulesAck);       // operating rules accepted
        $this->assertNotNull($user->gdprConsentAt);
        $this->assertNotNull($user->rulesAckAt);
    }
}
