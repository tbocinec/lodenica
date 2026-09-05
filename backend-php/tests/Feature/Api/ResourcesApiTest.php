<?php

namespace Tests\Feature\Api;

use App\Domain\Enums\ResourceType;
use App\Domain\Enums\UserRole;
use App\Models\AuditLog;
use App\Models\Resource;
use App\Models\User;
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

    public function test_update_can_change_identifier_and_type(): void
    {
        $this->actingAsAdmin();
        $r = Resource::create([
            'identifier' => 'K-OLD', 'type' => ResourceType::WW_KAYAK, 'name' => 'X',
        ]);
        $this->patchJson("/api/v1/resources/{$r->id}", [
            'identifier' => 'C-NEW', 'type' => 'CANOE',
        ])->assertOk()
            ->assertJsonPath('identifier', 'C-NEW')
            ->assertJsonPath('type', 'CANOE');
    }

    public function test_update_identifier_must_stay_unique(): void
    {
        $this->actingAsAdmin();
        Resource::create(['identifier' => 'K-DUP', 'type' => ResourceType::WW_KAYAK, 'name' => 'A']);
        $r = Resource::create(['identifier' => 'K-ME', 'type' => ResourceType::WW_KAYAK, 'name' => 'B']);

        $this->patchJson("/api/v1/resources/{$r->id}", ['identifier' => 'K-DUP'])
            ->assertStatus(400)
            ->assertJsonPath('code', 'VALIDATION_ERROR');

        // Keeping its own identifier is fine (ignore-self).
        $this->patchJson("/api/v1/resources/{$r->id}", ['identifier' => 'K-ME', 'name' => 'B2'])
            ->assertOk();
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

    private function memberUser(string $email): User
    {
        return User::create(['name' => 'M '.$email, 'email' => $email, 'password' => 'password123', 'role' => UserRole::MEMBER, 'isActive' => true]);
    }

    public function test_admin_sets_requires_approval_and_approvers(): void
    {
        $this->actingAsAdmin();
        $a = $this->memberUser('a@example.test');
        $b = $this->memberUser('b@example.test');

        $created = $this->postJson('/api/v1/resources', [
            'identifier' => 'S-1', 'type' => 'BOATHOUSE_SPACE', 'name' => 'Klubovňa',
            'requiresApproval' => true, 'approverIds' => [$a->id, $b->id],
        ])->assertCreated()->assertJsonPath('requiresApproval', true);
        $this->assertEqualsCanonicalizing([$a->id, $b->id], collect($created->json('approvers'))->pluck('id')->all());
        $this->assertSame('M a@example.test', collect($created->json('approvers'))->firstWhere('id', $a->id)['name']);

        $id = $created->json('id');
        $updated = $this->patchJson("/api/v1/resources/{$id}", ['approverIds' => [$b->id]])->assertOk();
        $this->assertSame([$b->id], collect($updated->json('approvers'))->pluck('id')->all());

        // Approver changes land in the audit log (REZ-050).
        $audit = AuditLog::query()->where('entityId', $id)->where('action', 'UPDATE')->latest('createdAt')->first();
        $this->assertNotNull($audit);
        $this->assertSame([$b->id], $audit->changes['after']['approverIds']);
    }

    public function test_a_patch_without_approver_ids_leaves_the_list_alone(): void
    {
        $this->actingAsAdmin();
        $a = $this->memberUser('a@example.test');
        $id = $this->postJson('/api/v1/resources', [
            'identifier' => 'S-2', 'type' => 'BOATHOUSE_SPACE', 'name' => 'Sklad',
            'requiresApproval' => true, 'approverIds' => [$a->id],
        ])->assertCreated()->json('id');

        $r = $this->patchJson("/api/v1/resources/{$id}", ['name' => 'Sklad 2'])->assertOk();

        $this->assertSame([$a->id], collect($r->json('approvers'))->pluck('id')->all());
    }

    public function test_approver_must_be_a_confirmed_member(): void
    {
        $this->actingAsAdmin();
        $pending = User::create(['name' => 'P', 'email' => 'p@example.test', 'password' => 'password123', 'role' => UserRole::PENDING, 'isActive' => true]);

        $this->postJson('/api/v1/resources', [
            'identifier' => 'S-3', 'type' => 'BOATHOUSE_SPACE', 'name' => 'X',
            'requiresApproval' => true, 'approverIds' => [$pending->id],
        ])->assertStatus(400)->assertJsonPath('code', 'VALIDATION_ERROR');
        $this->assertDatabaseMissing('resources', ['identifier' => 'S-3']);

        $this->postJson('/api/v1/resources', [
            'identifier' => 'S-4', 'type' => 'BOATHOUSE_SPACE', 'name' => 'Y',
            'approverIds' => ['00000000-0000-0000-0000-000000000000'],
        ])->assertStatus(400);
        $this->assertDatabaseMissing('resources', ['identifier' => 'S-4']);

        $r = Resource::create(['identifier' => 'S-6', 'type' => ResourceType::BOATHOUSE_SPACE, 'name' => 'Pôvodný']);
        $this->patchJson("/api/v1/resources/{$r->id}", [
            'name' => 'Zmenený', 'approverIds' => [$pending->id],
        ])->assertStatus(400);
        $this->assertDatabaseHas('resources', ['identifier' => 'S-6', 'name' => 'Pôvodný']);
    }

    public function test_approvers_are_visible_to_members_only(): void
    {
        $a = $this->memberUser('a@example.test');
        $r = Resource::create(['identifier' => 'S-5', 'type' => ResourceType::BOATHOUSE_SPACE, 'name' => 'Z', 'requiresApproval' => true]);
        $r->approvers()->attach($a->id);

        $this->getJson("/api/v1/resources/{$r->id}")->assertOk()
            ->assertJsonPath('requiresApproval', true)
            ->assertJsonPath('approvers', null);

        $this->actingAsMember();
        $this->getJson("/api/v1/resources/{$r->id}")->assertOk()->assertJsonPath('approvers.0.id', $a->id);
        $this->assertSame($a->id, $this->getJson('/api/v1/resources')->json('items.0.approvers.0.id'));
    }
}
