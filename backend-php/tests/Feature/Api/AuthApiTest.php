<?php

namespace Tests\Feature\Api;

use App\Domain\Enums\UserRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_returns_token_and_user(): void
    {
        User::create([
            'name' => 'Admin',
            'email' => 'admin@example.test',
            'password' => 'password123',
            'role' => UserRole::ADMIN,
            'isActive' => true,
        ]);

        $response = $this->postJson('/api/v1/auth/login', [
            'email' => 'admin@example.test',
            'password' => 'password123',
        ]);

        $response->assertOk()
            ->assertJsonStructure(['token', 'user' => ['id', 'name', 'email', 'role']])
            ->assertJsonPath('user.email', 'admin@example.test')
            ->assertJsonPath('user.role', 'ADMIN');

        $this->assertNotEmpty($response->json('token'));
    }

    public function test_login_with_wrong_password_returns_401(): void
    {
        User::create([
            'name' => 'X', 'email' => 'x@example.test', 'password' => 'right',
            'role' => UserRole::MEMBER, 'isActive' => true,
        ]);

        $this->postJson('/api/v1/auth/login', [
            'email' => 'x@example.test',
            'password' => 'wrong',
        ])
            ->assertStatus(401)
            ->assertJsonPath('code', 'INVALID_CREDENTIALS');
    }

    public function test_login_with_unknown_email_returns_401_same_message(): void
    {
        $this->postJson('/api/v1/auth/login', [
            'email' => 'nobody@example.test',
            'password' => 'whatever',
        ])
            ->assertStatus(401)
            ->assertJsonPath('code', 'INVALID_CREDENTIALS');
    }

    public function test_login_with_inactive_user_returns_401(): void
    {
        User::create([
            'name' => 'X', 'email' => 'inactive@example.test', 'password' => 'pw12345678',
            'role' => UserRole::MEMBER, 'isActive' => false,
        ]);

        $this->postJson('/api/v1/auth/login', [
            'email' => 'inactive@example.test',
            'password' => 'pw12345678',
        ])
            ->assertStatus(401)
            ->assertJsonPath('code', 'INVALID_CREDENTIALS');
    }

    public function test_me_returns_current_user(): void
    {
        $user = $this->actingAsMember(['email' => 'me@example.test']);
        $this->getJson('/api/v1/auth/me')
            ->assertOk()
            ->assertJsonPath('email', 'me@example.test')
            ->assertJsonPath('id', $user->id);
    }

    public function test_me_requires_auth(): void
    {
        $this->getJson('/api/v1/auth/me')->assertStatus(401);
    }

    public function test_logout_returns_204(): void
    {
        $this->actingAsMember();
        $this->postJson('/api/v1/auth/logout')->assertStatus(204);
    }
}
