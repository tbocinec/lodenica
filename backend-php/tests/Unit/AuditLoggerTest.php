<?php

namespace Tests\Unit;

use App\Domain\Enums\AuditAction;
use App\Domain\Enums\AuditEntityType;
use App\Domain\Enums\ResourceType;
use App\Models\AuditLog;
use App\Models\Resource;
use App\Services\AuditLogger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuditLoggerTest extends TestCase
{
    use RefreshDatabase;

    public function test_logCreate_writes_after_snapshot(): void
    {
        $logger = app(AuditLogger::class);
        $resource = Resource::create([
            'identifier' => 'K-100',
            'type' => ResourceType::WW_KAYAK,
            'name' => 'Test',
        ]);

        $logger->logCreate(
            AuditEntityType::RESOURCE,
            $resource,
            'Pridaná loď „K-100“',
            ['identifier' => 'K-100', 'name' => 'Test'],
        );

        $row = AuditLog::first();
        $this->assertNotNull($row);
        $this->assertSame(AuditEntityType::RESOURCE, $row->entityType);
        $this->assertSame(AuditAction::CREATE, $row->action);
        $this->assertSame($resource->id, $row->entityId);
        $this->assertSame(['after' => ['identifier' => 'K-100', 'name' => 'Test']], $row->changes);
    }

    public function test_logUpdate_records_only_changed_fields(): void
    {
        $logger = app(AuditLogger::class);
        $resource = Resource::create([
            'identifier' => 'K-101',
            'type' => ResourceType::WW_KAYAK,
            'name' => 'Old name',
        ]);

        $logger->logUpdate(
            AuditEntityType::RESOURCE,
            $resource,
            'Upravená loď',
            ['identifier' => 'K-101', 'name' => 'Old name', 'color' => 'red'],
            ['identifier' => 'K-101', 'name' => 'New name', 'color' => 'red'],
        );

        $row = AuditLog::first();
        $this->assertNotNull($row);
        $this->assertSame(['name' => 'Old name'], $row->changes['before']);
        $this->assertSame(['name' => 'New name'], $row->changes['after']);
        $this->assertArrayNotHasKey('identifier', $row->changes['before']);
    }

    public function test_logUpdate_skips_when_nothing_changed(): void
    {
        $logger = app(AuditLogger::class);
        $resource = Resource::create([
            'identifier' => 'K-102',
            'type' => ResourceType::WW_KAYAK,
            'name' => 'Same',
        ]);

        $result = $logger->logUpdate(
            AuditEntityType::RESOURCE,
            $resource,
            'No-op',
            ['name' => 'Same'],
            ['name' => 'Same'],
        );

        $this->assertNull($result);
        $this->assertSame(0, AuditLog::count());
    }

    public function test_logAction_with_arbitrary_changes_payload(): void
    {
        $logger = app(AuditLogger::class);
        $resource = Resource::create([
            'identifier' => 'K-103',
            'type' => ResourceType::WW_KAYAK,
            'name' => 'X',
        ]);

        $logger->logAction(
            AuditEntityType::RESOURCE,
            $resource->id,
            AuditAction::DEACTIVATE,
            'Deaktivovaná',
            ['before' => ['isActive' => true], 'after' => ['isActive' => false]],
        );

        $row = AuditLog::first();
        $this->assertSame(AuditAction::DEACTIVATE, $row->action);
        $this->assertSame(['isActive' => false], $row->changes['after']);
    }
}
