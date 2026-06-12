<?php

namespace Tests\Feature\Api;

use App\Domain\Enums\ResourceType;
use App\Models\Resource;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ResourcesApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_create_resource_returns_201_and_body(): void
    {
        $this->actingAsAdmin();
        $response = $this->postJson('/api/v1/resources', [
            'identifier' => 'K-001',
            'type' => 'WW_KAYAK',
            'name' => 'Kayak 1',
            'seats' => 1,
        ]);

        $response->assertCreated()
            ->assertJsonStructure([
                'id', 'identifier', 'type', 'name', 'isActive', 'createdAt',
            ])
            ->assertJsonPath('identifier', 'K-001')
            ->assertJsonPath('type', 'WW_KAYAK');
    }

    public function test_create_rejects_invalid_identifier(): void
    {
        $this->actingAsAdmin();
        $this->postJson('/api/v1/resources', [
            'identifier' => 'has space',
            'type' => 'WW_KAYAK',
            'name' => 'Kayak',
        ])
            ->assertStatus(400)
            ->assertJsonPath('code', 'VALIDATION_ERROR');
    }

    public function test_create_requires_admin(): void
    {
        // Anonymous → 401
        $this->postJson('/api/v1/resources', [
            'identifier' => 'K-A', 'type' => 'WW_KAYAK', 'name' => 'X',
        ])->assertStatus(401);

        // Member → 403
        $this->actingAsMember();
        $this->postJson('/api/v1/resources', [
            'identifier' => 'K-B', 'type' => 'WW_KAYAK', 'name' => 'X',
        ])->assertStatus(403);
    }

    public function test_list_with_filter_and_pagination(): void
    {
        Resource::create(['identifier' => 'K-A', 'type' => ResourceType::WW_KAYAK, 'name' => 'A']);
        Resource::create(['identifier' => 'C-B', 'type' => ResourceType::CANOE, 'name' => 'B']);

        $response = $this->getJson('/api/v1/resources?type=CANOE&pageSize=5');

        $response->assertOk()
            ->assertJsonPath('total', 1)
            ->assertJsonPath('page', 1)
            ->assertJsonPath('pageSize', 5)
            ->assertJsonPath('items.0.identifier', 'C-B');
    }

    public function test_show_returns_404_for_unknown_id(): void
    {
        $this->getJson('/api/v1/resources/00000000-0000-0000-0000-000000000000')
            ->assertStatus(404)
            ->assertJsonPath('code', 'RESOURCE_NOT_FOUND');
    }

    public function test_update_changes_name(): void
    {
        $this->actingAsAdmin();
        $r = Resource::create([
            'identifier' => 'K-9', 'type' => ResourceType::WW_KAYAK, 'name' => 'Old',
        ]);
        $this->patchJson("/api/v1/resources/{$r->id}", ['name' => 'New'])
            ->assertOk()
            ->assertJsonPath('name', 'New');
    }

    public function test_deactivate_and_activate(): void
    {
        $this->actingAsAdmin();
        $r = Resource::create([
            'identifier' => 'K-X', 'type' => ResourceType::WW_KAYAK, 'name' => 'X',
        ]);
        $this->patchJson("/api/v1/resources/{$r->id}/deactivate")
            ->assertOk()
            ->assertJsonPath('isActive', false);
        $this->patchJson("/api/v1/resources/{$r->id}/activate")
            ->assertOk()
            ->assertJsonPath('isActive', true);
    }

    public function test_delete_returns_204(): void
    {
        $this->actingAsAdmin();
        $r = Resource::create([
            'identifier' => 'K-Y', 'type' => ResourceType::WW_KAYAK, 'name' => 'Y',
        ]);
        $this->deleteJson("/api/v1/resources/{$r->id}")
            ->assertStatus(204);
        $this->getJson("/api/v1/resources/{$r->id}")
            ->assertStatus(404);
    }
}
