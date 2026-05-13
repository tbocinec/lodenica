<?php

namespace Tests\Feature\Api;

use App\Domain\Enums\ResourceType;
use App\Models\Resource;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DamagesApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_create_damage_then_mark_fixed_sets_fixedAt(): void
    {
        $r = Resource::create([
            'identifier' => 'K-1', 'type' => ResourceType::WW_KAYAK, 'name' => 'K1',
        ]);

        $createResp = $this->postJson('/api/v1/damages', [
            'resourceId' => $r->id,
            'description' => 'Trhlina.',
            'severity' => 'MODERATE',
        ])
            ->assertCreated()
            ->assertJsonPath('data.status', 'REPORTED')
            ->assertJsonPath('data.fixedAt', null);

        $damageId = $createResp->json('data.id');

        $this->patchJson("/api/v1/damages/{$damageId}", ['status' => 'FIXED'])
            ->assertOk()
            ->assertJsonPath('data.status', 'FIXED');

        $reloaded = $this->getJson("/api/v1/damages/{$damageId}")
            ->assertOk();
        $this->assertNotNull($reloaded->json('data.fixedAt'));
    }

    public function test_create_damage_returns_404_on_missing_resource(): void
    {
        $this->postJson('/api/v1/damages', [
            'resourceId' => '00000000-0000-0000-0000-000000000000',
            'description' => 'Trhlina.',
            'severity' => 'MINOR',
        ])
            ->assertStatus(404)
            ->assertJsonPath('code', 'RESOURCE_NOT_FOUND');
    }
}
