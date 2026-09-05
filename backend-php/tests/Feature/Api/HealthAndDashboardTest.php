<?php

namespace Tests\Feature\Api;

use App\Domain\Enums\DamageSeverity;
use App\Domain\Enums\DamageStatus;
use App\Domain\Enums\ReservationStatus;
use App\Domain\Enums\ResourceType;
use App\Models\Damage;
use App\Models\Reservation;
use App\Models\Resource;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HealthAndDashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_health_endpoint_reports_ok(): void
    {
        $this->getJson('/health')
            ->assertOk()
            ->assertJsonStructure(['status', 'database', 'uptimeSeconds'])
            ->assertJsonPath('status', 'ok')
            ->assertJsonPath('database', 'up');
    }

    public function test_health_ready_and_live(): void
    {
        $this->getJson('/health/ready')->assertOk()->assertJson(['ready' => true]);
        $this->getJson('/health/live')->assertOk()->assertJson(['live' => true]);
    }

    public function test_dashboard_returns_snapshot_on_empty_db(): void
    {
        $this->getJson('/api/v1/availability/dashboard')
            ->assertOk()
            ->assertJsonStructure([
                'generatedAt', 'today',
                'occupiedToday', 'occupiedTomorrow', 'upcoming',
                'spaceReservations', 'available', 'damaged',
                'totals' => ['activeResources', 'upcomingReservations', 'openDamages'],
            ])
            ->assertJsonPath('totals.activeResources', 0)
            ->assertJsonPath('totals.upcomingReservations', 0)
            ->assertJsonPath('totals.openDamages', 0);
    }

    /**
     * The dashboard's damage card is for damages that still matter. Fixed
     * ones belong in the damages module's history, not on the front page.
     */
    public function test_dashboard_damage_card_lists_only_open_damages(): void
    {
        $broken = Resource::create([
            'identifier' => 'K-OPEN', 'type' => ResourceType::WW_KAYAK, 'name' => 'Otvorené',
        ]);
        $repaired = Resource::create([
            'identifier' => 'K-DONE', 'type' => ResourceType::WW_KAYAK, 'name' => 'Opravené',
        ]);
        $inRepair = Resource::create([
            'identifier' => 'K-WIP', 'type' => ResourceType::WW_KAYAK, 'name' => 'V oprave',
        ]);

        Damage::create([
            'resourceId' => $broken->id, 'description' => 'nahlásené',
            'severity' => DamageSeverity::MODERATE, 'status' => DamageStatus::REPORTED,
        ]);
        Damage::create([
            'resourceId' => $inRepair->id, 'description' => 'v oprave',
            'severity' => DamageSeverity::MINOR, 'status' => DamageStatus::IN_REPAIR,
        ]);
        Damage::create([
            'resourceId' => $repaired->id, 'description' => 'už opravené',
            'severity' => DamageSeverity::CRITICAL, 'status' => DamageStatus::FIXED,
        ]);

        $r = $this->getJson('/api/v1/availability/dashboard')->assertOk();

        $statuses = array_column($r->json('damaged'), 'status');
        sort($statuses);
        $this->assertSame(['IN_REPAIR', 'REPORTED'], $statuses);
        $r->assertJsonPath('totals.openDamages', 2);
    }

    /**
     * The card shows the boat number, so the payload has to carry it.
     */
    public function test_dashboard_damage_card_carries_the_resource_identifier(): void
    {
        $boat = Resource::create([
            'identifier' => 'K-042', 'type' => ResourceType::WW_KAYAK, 'name' => 'Číslovaná',
        ]);
        Damage::create([
            'resourceId' => $boat->id, 'description' => 'prasklina',
            'severity' => DamageSeverity::MODERATE, 'status' => DamageStatus::REPORTED,
        ]);

        $this->getJson('/api/v1/availability/dashboard')
            ->assertOk()
            ->assertJsonPath('damaged.0.resource.identifier', 'K-042');
    }

    public function test_dashboard_counts_a_pending_request_as_occupied(): void
    {
        $space = Resource::create(['identifier' => 'S-1', 'type' => ResourceType::CANOE, 'name' => 'Gated canoe', 'requiresApproval' => true]);
        $now = CarbonImmutable::now();
        Reservation::create([
            'resourceId' => $space->id, 'customerName' => 'P',
            'startsAt' => $now->startOfDay()->addHours(9), 'endsAt' => $now->startOfDay()->addHours(12),
            'status' => ReservationStatus::PENDING_APPROVAL,
        ]);

        $r = $this->getJson('/api/v1/availability/dashboard')->assertOk();

        $this->assertContains($space->id, collect($r->json('occupiedToday'))->pluck('resourceId')->all());
        $this->assertSame('PENDING_APPROVAL', collect($r->json('occupiedToday'))->firstWhere('resourceId', $space->id)['status']);
        $this->assertNotContains($space->id, collect($r->json('available'))->pluck('id')->all());
        $this->assertTrue(collect($r->json('occupiedToday'))->firstWhere('resourceId', $space->id)['resource']['requiresApproval']);
    }
}
