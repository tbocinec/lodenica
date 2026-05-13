<?php

namespace Tests\Feature\Api;

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
}
