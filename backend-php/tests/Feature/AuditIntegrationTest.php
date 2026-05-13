<?php

namespace Tests\Feature;

use App\Domain\Enums\AuditAction;
use App\Domain\Enums\AuditEntityType;
use App\Domain\Enums\ResourceType;
use App\Models\AuditLog;
use App\Models\Resource;
use App\Services\DamagesService;
use App\Services\EventsService;
use App\Services\ReservationsService;
use App\Services\ResourcesService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Verifies that the four core services emit the audit rows we expect.
 * This is the seam between business code and the audit log — if it
 * regresses (a service forgets to call AuditLogger), these tests fail.
 */
class AuditIntegrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_resource_create_update_delete_emits_three_audit_rows(): void
    {
        $svc = app(ResourcesService::class);
        $r = $svc->create([
            'identifier' => 'K-200',
            'type' => ResourceType::WW_KAYAK,
            'name' => 'Burn',
        ]);
        $svc->update($r->id, ['color' => 'red']);
        $svc->delete($r->id);

        $rows = AuditLog::where('entityId', $r->id)->orderBy('createdAt')->get();
        $this->assertCount(3, $rows);
        $this->assertSame(AuditAction::CREATE, $rows[0]->action);
        $this->assertSame(AuditAction::UPDATE, $rows[1]->action);
        $this->assertSame(AuditAction::DELETE, $rows[2]->action);
        $this->assertSame('red', $rows[1]->changes['after']['color']);
    }

    public function test_resource_setActive_emits_only_when_state_actually_changes(): void
    {
        $svc = app(ResourcesService::class);
        $r = $svc->create([
            'identifier' => 'K-201',
            'type' => ResourceType::CANOE,
            'name' => 'Old Town',
        ]);
        // Idempotent re-activate — already active, must not emit.
        $svc->setActive($r->id, true);
        $svc->setActive($r->id, false);

        $deactivations = AuditLog::where('entityId', $r->id)
            ->where('action', AuditAction::DEACTIVATE->value)
            ->count();
        $activations = AuditLog::where('entityId', $r->id)
            ->where('action', AuditAction::ACTIVATE->value)
            ->count();

        $this->assertSame(1, $deactivations);
        $this->assertSame(0, $activations);
    }

    public function test_reservation_lifecycle_emits_create_update_cancel_delete(): void
    {
        $resources = app(ResourcesService::class);
        $reservations = app(ReservationsService::class);

        $r = $resources->create([
            'identifier' => 'K-202',
            'type' => ResourceType::SEA_KAYAK,
            'name' => 'P&H',
        ]);

        $res = $reservations->create([
            'resourceId' => $r->id,
            'customerName' => 'Tomáš',
            'startsAt' => '2099-06-01T08:00:00Z',
            'endsAt' => '2099-06-01T10:00:00Z',
        ]);
        $reservations->update($res->id, ['note' => 'Pozor na vlnu']);
        $reservations->cancel($res->id);
        $reservations->remove($res->id);

        $actions = AuditLog::where('entityType', AuditEntityType::RESERVATION->value)
            ->where('entityId', $res->id)
            ->orderBy('createdAt')
            ->get()
            ->map(fn (AuditLog $row) => $row->action->value)
            ->all();

        $this->assertEquals(['CREATE', 'UPDATE', 'CANCEL', 'DELETE'], $actions);
    }

    public function test_reservation_cancel_on_already_cancelled_does_not_double_log(): void
    {
        $resources = app(ResourcesService::class);
        $reservations = app(ReservationsService::class);

        $r = $resources->create([
            'identifier' => 'K-203',
            'type' => ResourceType::SEA_KAYAK,
            'name' => 'P&H',
        ]);
        $res = $reservations->create([
            'resourceId' => $r->id,
            'customerName' => 'X',
            'startsAt' => '2099-07-01T08:00:00Z',
            'endsAt' => '2099-07-01T10:00:00Z',
        ]);
        $reservations->cancel($res->id);
        $reservations->cancel($res->id); // no-op

        $cancels = AuditLog::where('entityId', $res->id)
            ->where('action', AuditAction::CANCEL->value)
            ->count();
        $this->assertSame(1, $cancels);
    }

    public function test_event_attach_resources_logs_per_reservation_and_one_summary(): void
    {
        $resources = app(ResourcesService::class);
        $events = app(EventsService::class);

        $r1 = $resources->create([
            'identifier' => 'K-300', 'type' => ResourceType::WW_KAYAK, 'name' => 'A',
        ]);
        $r2 = $resources->create([
            'identifier' => 'K-301', 'type' => ResourceType::WW_KAYAK, 'name' => 'B',
        ]);

        $event = $events->create([
            'title' => 'Splav Hrona',
            'startsAt' => '2099-08-01T08:00:00Z',
            'endsAt' => '2099-08-01T16:00:00Z',
        ]);

        $events->attachResources($event->id, [$r1->id, $r2->id]);

        // 1 EVENT/CREATE + 1 EVENT/ATTACH_RESOURCES + 2 RESERVATION/CREATE
        $eventLogs = AuditLog::where('entityType', AuditEntityType::EVENT->value)
            ->where('entityId', $event->id)->count();
        $reservationCreates = AuditLog::where('entityType', AuditEntityType::RESERVATION->value)
            ->where('action', AuditAction::CREATE->value)->count();

        $this->assertSame(2, $eventLogs);
        $this->assertSame(2, $reservationCreates);
    }

    public function test_damage_create_update_logs_via_audit(): void
    {
        $resources = app(ResourcesService::class);
        $damages = app(DamagesService::class);

        $r = $resources->create([
            'identifier' => 'K-400', 'type' => ResourceType::CANOE, 'name' => 'Old Town',
        ]);
        $d = $damages->create([
            'resourceId' => $r->id,
            'description' => 'Diera',
            'severity' => 'MODERATE',
        ]);
        $damages->update($d->id, ['status' => 'FIXED']);

        $rows = AuditLog::where('entityType', AuditEntityType::DAMAGE->value)
            ->where('entityId', $d->id)
            ->orderBy('createdAt')
            ->get()
            ->map(fn (AuditLog $row) => $row->action->value)
            ->all();
        $this->assertEquals(['CREATE', 'UPDATE'], $rows);
    }
}
