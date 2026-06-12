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

    private function makeUser(UserRole $role, array $overrides): User
    {
        $suffix = bin2hex(random_bytes(4));

        return User::create(array_merge([
            'name' => $role === UserRole::ADMIN ? 'Test Admin' : 'Test Member',
            'email' => "test.{$role->value}.{$suffix}@example.test",
            'password' => 'password123',
            'role' => $role,
            'isActive' => true,
        ], $overrides));
    }
}
