<?php

namespace Tests\Feature\Api;

use App\Domain\Enums\AuditAction;
use App\Domain\Enums\AuditEntityType;
use App\Domain\Enums\ResourceType;
use App\Models\AuditLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class AuditLogsApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_index_returns_paginated_audit_logs_most_recent_first(): void
    {
        // Create some entries via the API so we exercise the full stack.
        $this->postJson('/api/v1/resources', [
            'identifier' => 'K-A', 'type' => 'WW_KAYAK', 'name' => 'A',
        ])->assertCreated();
        $this->postJson('/api/v1/resources', [
            'identifier' => 'K-B', 'type' => 'CANOE', 'name' => 'B',
        ])->assertCreated();

        $response = $this->getJson('/api/v1/audit-logs');
        $response->assertOk()
            ->assertJsonStructure([
                'items' => [['id', 'entityType', 'entityId', 'action', 'summary', 'changes', 'createdAt']],
                'total', 'page', 'pageSize',
            ])
            ->assertJsonPath('total', 2)
            ->assertJsonPath('items.0.action', 'CREATE');
    }

    public function test_index_filters_by_entityType(): void
    {
        // Seed two different entity types directly via the model so the test
        // is deterministic regardless of service wiring.
        AuditLog::create([
            'entityType' => AuditEntityType::RESOURCE,
            'entityId' => (string) Str::uuid(),
            'action' => AuditAction::CREATE,
            'summary' => 'Resource thing',
        ]);
        AuditLog::create([
            'entityType' => AuditEntityType::DAMAGE,
            'entityId' => (string) Str::uuid(),
            'action' => AuditAction::CREATE,
            'summary' => 'Damage thing',
        ]);

        $this->getJson('/api/v1/audit-logs?entityType=DAMAGE')
            ->assertOk()
            ->assertJsonPath('total', 1)
            ->assertJsonPath('items.0.summary', 'Damage thing');
    }

    public function test_index_filters_by_entityId(): void
    {
        $targetId = (string) Str::uuid();
        $otherId = (string) Str::uuid();

        AuditLog::create([
            'entityType' => AuditEntityType::RESERVATION,
            'entityId' => $targetId,
            'action' => AuditAction::CREATE,
            'summary' => 'Mine',
        ]);
        AuditLog::create([
            'entityType' => AuditEntityType::RESERVATION,
            'entityId' => $otherId,
            'action' => AuditAction::CREATE,
            'summary' => 'Other',
        ]);

        $this->getJson("/api/v1/audit-logs?entityId={$targetId}")
            ->assertOk()
            ->assertJsonPath('total', 1)
            ->assertJsonPath('items.0.summary', 'Mine');
    }

    public function test_index_rejects_unknown_entityType(): void
    {
        // The Lodenica error envelope flattens validation errors to 400.
        $this->getJson('/api/v1/audit-logs?entityType=BOGUS')
            ->assertStatus(400)
            ->assertJsonPath('code', 'VALIDATION_ERROR');
    }

    public function test_index_supports_pagination(): void
    {
        for ($i = 0; $i < 5; $i++) {
            AuditLog::create([
                'entityType' => AuditEntityType::RESOURCE,
                'entityId' => (string) Str::uuid(),
                'action' => AuditAction::CREATE,
                'summary' => "Row {$i}",
            ]);
        }

        $page2 = $this->getJson('/api/v1/audit-logs?page=2&pageSize=2')
            ->assertOk()
            ->assertJsonPath('total', 5)
            ->assertJsonPath('page', 2)
            ->assertJsonPath('pageSize', 2);
        $this->assertCount(2, $page2->json('items'));
    }
}
