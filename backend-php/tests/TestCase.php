<?php

namespace Tests;

use App\Domain\Enums\UserRole;
use App\Models\User;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Laravel\Sanctum\Sanctum;

abstract class TestCase extends BaseTestCase
{
    /**
     * Authenticate the next request as a freshly-created admin and return
     * the User instance (handy when a test needs the admin's id). Uses
     * Sanctum's `actingAs($user, ['*'])` so route middleware sees a real
     * token-authenticated session.
     */
    protected function actingAsAdmin(array $overrides = []): User
    {
        $user = $this->makeUser(UserRole::ADMIN, $overrides);
        Sanctum::actingAs($user, ['*']);

        return $user;
    }

    /**
     * Same as {@see actingAsAdmin} but with the MEMBER role.
     */
    protected function actingAsMember(array $overrides = []): User
    {
        $user = $this->makeUser(UserRole::MEMBER, $overrides);
        Sanctum::actingAs($user, ['*']);

        return $user;
    }

    /**
     * Same pattern with the PENDING role — registered but not yet
     * confirmed by an admin. Useful for asserting that PENDING is
     * treated as anonymous-grade for permission purposes.
     */
    protected function actingAsPending(array $overrides = []): User
    {
        $user = $this->makeUser(UserRole::PENDING, $overrides);
        Sanctum::actingAs($user, ['*']);

        return $user;
    }

    private function makeUser(UserRole $role, array $overrides): User
    {
        $suffix = bin2hex(random_bytes(4));
        $label = match ($role) {
            UserRole::ADMIN => 'Admin',
            UserRole::MEMBER => 'Member',
            UserRole::PENDING => 'Pending',
        };

        return User::create(array_merge([
            'name' => "Test {$label}",
            'email' => "test.{$role->value}.{$suffix}@example.test",
            'password' => 'password123',
            'role' => $role,
            'isActive' => true,
        ], $overrides));
    }
}
