<?php

namespace Tests\Feature\Api;

use App\Models\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReservationRulesApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_anonymous_can_read_rules(): void
    {
        $this->getJson('/api/v1/reservation-rules')
            ->assertOk()
            ->assertJsonStructure(['content', 'updatedAt']);
    }

    public function test_default_rules_seed_is_present(): void
    {
        $this->getJson('/api/v1/reservation-rules')
            ->assertOk()
            ->assertJsonPath('content', fn ($content) => str_contains((string) $content, 'Pravidlá rezervácie'));
    }

    public function test_anonymous_cannot_update_rules(): void
    {
        $this->patchJson('/api/v1/reservation-rules', ['content' => '<p>nope</p>'])
            ->assertStatus(401);
    }

    public function test_member_cannot_update_rules(): void
    {
        $this->actingAsMember();
        $this->patchJson('/api/v1/reservation-rules', ['content' => '<p>nope</p>'])
            ->assertStatus(403);
    }

    public function test_admin_can_update_rules(): void
    {
        $this->actingAsAdmin();
        $newHtml = '<h2>Nové pravidlá</h2><p>Nový obsah pravidiel.</p>';
        $this->patchJson('/api/v1/reservation-rules', ['content' => $newHtml])
            ->assertOk()
            ->assertJsonPath('content', $newHtml);

        // GET should reflect the change for anonymous callers too.
        $this->getJson('/api/v1/reservation-rules')
            ->assertOk()
            ->assertJsonPath('content', $newHtml);
    }

    public function test_admin_update_validates_required_content(): void
    {
        $this->actingAsAdmin();
        $this->patchJson('/api/v1/reservation-rules', [])
            ->assertStatus(400)
            ->assertJsonPath('code', 'VALIDATION_ERROR');
    }

    public function test_script_tags_are_stripped_on_save(): void
    {
        $this->actingAsAdmin();
        $payload = '<p>safe</p><script>alert(1)</script><p>also safe</p>';
        $this->patchJson('/api/v1/reservation-rules', ['content' => $payload])
            ->assertOk();

        $stored = Setting::find('reservation_rules')->value;
        $this->assertStringNotContainsString('<script', $stored);
        $this->assertStringContainsString('safe', $stored);
    }

    public function test_inline_event_handlers_are_stripped(): void
    {
        $this->actingAsAdmin();
        $payload = '<p onclick="alert(1)">hello</p>';
        $this->patchJson('/api/v1/reservation-rules', ['content' => $payload])
            ->assertOk();

        $stored = Setting::find('reservation_rules')->value;
        $this->assertStringNotContainsString('onclick', $stored);
        $this->assertStringContainsString('<p', $stored);
        $this->assertStringContainsString('hello', $stored);
    }
}
