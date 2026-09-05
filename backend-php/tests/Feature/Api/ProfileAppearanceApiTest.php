<?php

namespace Tests\Feature\Api;

use App\Models\AuditLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** THEME-001: a user's own theme, or null for the site default. */
class ProfileAppearanceApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_anonymous_cannot_set_a_theme(): void
    {
        $this->patchJson('/api/v1/profile/appearance', ['theme' => 'forest'])->assertStatus(401);
    }

    public function test_user_sets_and_clears_their_theme(): void
    {
        $user = $this->actingAsPending();

        $this->patchJson('/api/v1/profile/appearance', ['theme' => 'forest'])
            ->assertOk()
            ->assertJsonPath('theme', 'forest');
        $this->getJson('/api/v1/auth/me')->assertJsonPath('theme', 'forest');
        $this->assertSame('forest', $user->fresh()->theme);
        $this->assertSame(1, AuditLog::query()->count(), 'the change is audited');

        $this->patchJson('/api/v1/profile/appearance', ['theme' => 'forest'])->assertOk();
        $this->assertSame(1, AuditLog::query()->count(), 'a no-op writes nothing');

        $this->patchJson('/api/v1/profile/appearance', ['theme' => null])
            ->assertOk()
            ->assertJsonPath('theme', null);
        $this->assertNull($user->fresh()->theme);
    }

    public function test_theme_must_be_a_slug(): void
    {
        $this->actingAsMember();

        $this->patchJson('/api/v1/profile/appearance', ['theme' => 'Bad Theme'])
            ->assertStatus(400)
            ->assertJsonPath('code', 'VALIDATION_ERROR');
        $this->patchJson('/api/v1/profile/appearance', [])->assertStatus(400);
    }
}
