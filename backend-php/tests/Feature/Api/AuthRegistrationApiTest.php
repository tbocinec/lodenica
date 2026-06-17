<?php

namespace Tests\Feature\Api;

use App\Domain\Enums\UserRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthRegistrationApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_public_registration_creates_pending_account_and_logs_in(): void
    {
        $resp = $this->postJson('/api/v1/auth/register', [
            'name' => 'Nový Pádlič',
            'email' => 'novy@example.test',
            'password' => 'tajneheslo123',
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
        ])->assertStatus(400)
            ->assertJsonPath('code', 'VALIDATION_ERROR');
    }

    public function test_registration_normalizes_email_case(): void
    {
        $this->postJson('/api/v1/auth/register', [
            'name' => 'Case',
            'email' => 'MixedCase@Example.TEST',
            'password' => 'tajneheslo123',
        ])->assertCreated();

        $this->assertDatabaseHas('users', ['email' => 'mixedcase@example.test']);
    }
}
